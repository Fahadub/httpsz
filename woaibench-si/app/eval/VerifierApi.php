<?php
/**
 * Verifier API (ARCHITECTURE §5.7): browser verifiers (admin Verification Station or headless
 * Playwright) claim runs, execute browser checks / tests on the sandboxed render, and submit results
 * and screenshots. Everything a verifier sends is treated as untrusted input.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

use DB;

final class VerifierApi
{
    public const LEASE = 300;
    public const SHOT_MAX_BYTES = 4194304;
    public const SHOT_MAX_PIXELS = 16000000;
    public const MAX_BODY_BYTES = 12582912;
    public const VIEWPORTS = [['name' => 'desktop', 'w' => 1280, 'h' => 800], ['name' => 'mobile', 'w' => 390, 'h' => 844]];
    private const AGENTS = ['station', 'playwright'];
    private const SHOT_COLUMNS = ['desktop' => 'screenshot', 'mobile' => 'screenshot_mobile'];

    /**
     * Gate for the verifier endpoints: "Authorization: Bearer <token scope verifier>", or an admin session
     * plus a valid X-CSRF-Token. Returns the default agent name; ends the request with 401/419 otherwise.
     */
    public static function authorize(): string
    {
        if (\Auth::apiToken('verifier') !== null) {
            return 'playwright';
        }
        if (\Auth::check()) {
            csrf_check();
            return 'station';
        }
        json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
    }

    /** Claim the next run awaiting browser verification. Returns the job description or null. */
    public static function next(string $agent = 'station'): ?array
    {
        if (Pipeline::verifyMode() === 'off') {
            return null;
        }
        $cands = DB::col(
            "SELECT r.id FROM runs r JOIN campaigns c ON c.id = r.campaign_id
              WHERE c.status = 'running' AND r.status IN ('awaiting_verify', 'verifying')
                AND (r.locked_until IS NULL OR r.locked_until < NOW()) AND r.verify_tries < ?
              ORDER BY r.id LIMIT 10",
            [Pipeline::MAX_TRIES]
        );
        foreach ($cands as $id) {
            $token = Lease::claim('runs', (int) $id, ['awaiting_verify', 'verifying'], 'verifying', self::LEASE, 'verify_tries', Pipeline::MAX_TRIES);
            if ($token === null) {
                continue;
            }
            $run = DB::one('SELECT id, task_id FROM runs WHERE id = ?', [(int) $id]);
            $task = Pipeline::loadTask((int) $run['task_id']);
            if (!$task) {
                Lease::write('runs', (int) $id, ['status' => 'awaiting_verify'], ['token' => $token]);
                continue;
            }
            return self::job((int) $run['id'], $token, $task);
        }
        return null;
    }

    /** Store a verifier's results for a run it holds and advance the state machine. */
    public static function submit(array $body): array
    {
        [$run, $err] = self::ownedRun($body);
        if ($err !== null) {
            return $err;
        }
        $task = Pipeline::loadTask((int) $run['task_id']);
        if (!$task) {
            return self::error('task_missing', 404);
        }
        $agent = self::agent($body['agent'] ?? null, 'station');
        $results = self::sanitizeResults(is_array($body['results'] ?? null) ? $body['results'] : [], $task);
        $results['agent'] = $agent;
        $results['verified_at'] = now();

        $set = ['browser_json' => to_json($results), 'verified' => 1, 'verified_by' => $agent];
        $warnings = [];
        $shots = is_array($body['screenshots'] ?? null) ? $body['screenshots'] : [];
        foreach (self::SHOT_COLUMNS as $vp => $col) {
            if (!isset($shots[$vp]) || $shots[$vp] === '' || $shots[$vp] === null) {
                continue;
            }
            $saved = is_string($shots[$vp]) ? self::saveShot((int) $run['id'], $vp, $shots[$vp]) : ['error' => 'not a data URL'];
            if (isset($saved['error'])) {
                $warnings[] = $vp . ' screenshot rejected: ' . $saved['error'];
                continue;
            }
            $set[$col] = $saved['path'];
        }
        $status = Pipeline::afterVerification($run, (string) $body['lock_token'], $set);
        if ($status === null) {
            return self::error('lease_lost', 409);
        }
        return ['ok' => true, 'next_status' => $status, 'warnings' => $warnings];
    }

    /** Verifier could not verify (infrastructure problem): back to the queue, or proceed unverified after 3 tries. */
    public static function release(array $body): array
    {
        [$run, $err] = self::ownedRun($body);
        if ($err !== null) {
            return $err;
        }
        $token = (string) $body['lock_token'];
        $msg = self::str($body['error'] ?? '', 300) ?: 'verifier error';
        if ((int) $run['verify_tries'] >= Pipeline::MAX_TRIES) {
            $status = Pipeline::afterVerification($run, $token, ['verified' => 0], [['code' => 'unverified_failed', 'detail' => $msg]]);
            return $status === null ? self::error('lease_lost', 409) : ['ok' => true, 'next_status' => $status];
        }
        // Growing hold-back (20 s, then 60 s) so a briefly unavailable verifier does not burn every try at once.
        $hold = 20 * (3 ** max(0, (int) $run['verify_tries'] - 1));
        $n = Lease::write('runs', (int) $run['id'], ['status' => 'awaiting_verify'], [
            'token' => $token, 'status' => ['verifying'], 'lease' => $hold, 'new_token' => null,
        ]);
        return $n === 1 ? ['ok' => true, 'next_status' => 'awaiting_verify'] : self::error('lease_lost', 409);
    }

    /** Absolute render URL for the verifier (signed with wb_render_vt() when the render module is installed). */
    public static function renderUrl(int $runId, string $token): string
    {
        $file = WB_APP . '/render/render.php';
        if (!function_exists('wb_render_vt') && is_file($file)) {
            require_once $file;
        }
        $q = ['verify' => 1];
        if (function_exists('wb_render_vt')) {
            $q['vt'] = wb_render_vt($runId, $token);
        }
        return url('/render/' . $runId, $q, true);
    }

    /**
     * Keep only known keys, matched to the task's own check definitions, with capped sizes:
     * {checks:[{id,type,pass,score,detail,weight,label_en,label_ar}], tests:[{name,pass,ms,got?,error?}], errors:[…], metrics:{…}}.
     */
    public static function sanitizeResults(array $raw, array $task): array
    {
        $checks = Pipeline::checks($task);
        $defs = is_array($checks['browser'] ?? null) ? array_values(array_filter($checks['browser'], 'is_array')) : [];
        $in = [];
        foreach (array_slice(is_array($raw['checks'] ?? null) ? array_values($raw['checks']) : [], 0, 200) as $r) {
            if (is_array($r)) {
                $in[] = [
                    'id' => is_scalar($r['id'] ?? null) ? self::str($r['id'], 64) : null,
                    'type' => is_scalar($r['type'] ?? null) ? self::str($r['type'], 32) : '',
                    'pass' => self::bool($r['pass'] ?? false),
                    'score' => is_numeric($r['score'] ?? null) ? round(clamp((float) $r['score'], 0.0, 1.0), 4) : null,
                    'detail' => self::str($r['detail'] ?? '', 300),
                ];
            }
        }
        $out = [];
        $used = [];
        foreach ($defs as $d) {
            $type = (string) ($d['type'] ?? '');
            $id = (string) ($d['id'] ?? $type);
            $hit = null;
            foreach ($in as $i => $r) {
                if (!isset($used[$i]) && $r['id'] === $id) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                foreach ($in as $i => $r) {
                    if (!isset($used[$i]) && ($r['id'] === null || $r['id'] === '') && $r['type'] === $type) {
                        $hit = $i;
                        break;
                    }
                }
            }
            $r = $hit !== null ? $in[$hit] : null;
            if ($hit !== null) {
                $used[$hit] = true;
            }
            $pass = $r !== null && $r['pass'];
            $out[] = [
                'id' => $id, 'type' => $type, 'pass' => $pass,
                'score' => $r !== null ? ($r['score'] ?? ($pass ? 1.0 : 0.0)) : 0.0,
                'detail' => $r !== null ? $r['detail'] : 'not reported',
                'weight' => is_numeric($d['weight'] ?? null) ? (float) $d['weight'] : 1.0,
                'label_en' => self::str($d['label_en'] ?? '', 120), 'label_ar' => self::str($d['label_ar'] ?? '', 120),
            ];
        }
        $cases = is_array($checks['tests']['cases'] ?? null) ? $checks['tests']['cases'] : [];
        $tests = [];
        foreach (array_slice(is_array($raw['tests'] ?? null) ? array_values($raw['tests']) : [], 0, max(1, count($cases))) as $t) {
            if (!is_array($t) || !$cases) {
                continue;
            }
            $row = [
                'name' => self::str($t['name'] ?? '', 120),
                'pass' => self::bool($t['pass'] ?? false),
                'ms' => is_numeric($t['ms'] ?? null) ? round(clamp((float) $t['ms'], 0.0, 1e7), 1) : null,
            ];
            if (array_key_exists('got', $t)) {
                $row['got'] = self::str(to_json($t['got']), 300);
            }
            if (!empty($t['error'])) {
                $row['error'] = self::str($t['error'], 300);
            }
            $tests[] = $row;
        }
        $errors = [];
        foreach (array_slice(is_array($raw['errors'] ?? null) ? array_values($raw['errors']) : [], 0, 30) as $e) {
            if (is_scalar($e) && ($s = self::str($e, 300)) !== '') {
                $errors[] = $s;
            }
        }
        $metrics = [];
        $m = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
        foreach (['load_ms', 'fps', 'js_errors', 'console_errors'] as $k) {
            if (isset($m[$k]) && is_numeric($m[$k])) {
                $metrics[$k] = round(clamp((float) $m[$k], 0.0, 1e9), 2);
            }
        }
        return ['checks' => $out, 'tests' => $tests, 'errors' => $errors, 'metrics' => $metrics];
    }

    /**
     * Validate a screenshot data URL (PNG / JPEG / WebP, ≤ 4 MB, sane dimensions), re-encode it through GD
     * (strips metadata and anything that is not pixels) and store it as uploads/shots/run_<id>_<viewport>.png
     * (or .webp when the PNG would exceed 4 MB). Returns ['path' => 'shots/…'] or ['error' => '…'].
     */
    public static function saveShot(int $runId, string $viewport, string $dataUrl): array
    {
        if (!isset(self::SHOT_COLUMNS[$viewport])) {
            return ['error' => 'unknown viewport'];
        }
        if (strlen($dataUrl) > (int) ceil(self::SHOT_MAX_BYTES * 4 / 3) + 256) {
            return ['error' => 'larger than 4 MB'];
        }
        $comma = strpos($dataUrl, ',');
        if ($comma === false || $comma > 64 || !preg_match('#^data:image/(png|jpeg|webp);base64$#i', substr($dataUrl, 0, $comma))) {
            return ['error' => 'not a PNG, JPEG or WebP data URL'];
        }
        $bytes = base64_decode(substr($dataUrl, $comma + 1), true);
        if ($bytes === false || $bytes === '') {
            return ['error' => 'invalid base64'];
        }
        if (strlen($bytes) > self::SHOT_MAX_BYTES) {
            return ['error' => 'larger than 4 MB'];
        }
        $img = self::normalizeImage($bytes);
        if (isset($img['error'])) {
            return $img;
        }
        $dir = WB_UPLOADS . '/shots';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['error' => 'storage unavailable'];
        }
        $name = 'run_' . $runId . '_' . $viewport . '.' . $img['ext'];
        $tmp = $dir . '/.' . $name . '.' . random_token(6) . '.tmp';
        if (@file_put_contents($tmp, $img['bytes'], LOCK_EX) === false || !@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            return ['error' => 'could not be stored'];
        }
        @chmod($dir . '/' . $name, 0644);
        return ['path' => 'shots/' . $name];
    }

    /** Magic bytes + getimagesizefromstring + GD re-encode. Returns ['bytes','ext'] or ['error']. */
    private static function normalizeImage(string $bytes): array
    {
        $magic = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => IMAGETYPE_PNG,
            str_starts_with($bytes, "\xFF\xD8\xFF") => IMAGETYPE_JPEG,
            substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => IMAGETYPE_WEBP,
            default => 0,
        };
        $info = $magic ? @getimagesizefromstring($bytes) : false;
        if (!$magic || !$info || (int) $info[2] !== $magic) {
            return ['error' => 'not a valid image'];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 16 || $h < 16 || $w > 8192 || $h > 32768 || $w * $h > self::SHOT_MAX_PIXELS) {
            return ['error' => 'unsupported dimensions ' . $w . '×' . $h];
        }
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return $magic === IMAGETYPE_PNG ? ['bytes' => $bytes, 'ext' => 'png'] : ['error' => 'only PNG is accepted on this server'];
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return ['error' => 'image could not be decoded'];
        }
        if (self::isUniform($im)) {
            // A single-colour capture is a screenshot failure, not evidence: it would mislead vision judges.
            // (A genuinely blank page is already reported by the 'nonblank' browser check.)
            imagedestroy($im);
            return ['error' => 'blank single-colour image (capture failed)'];
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        ob_start();
        $ok = imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        $out = ['bytes' => $png, 'ext' => 'png'];
        if ((!$ok || strlen($png) > self::SHOT_MAX_BYTES) && function_exists('imagewebp')) {
            ob_start();
            $ok = imagewebp($im, null, 82);
            $out = ['bytes' => (string) ob_get_clean(), 'ext' => 'webp'];
        }
        imagedestroy($im);
        if (!$ok || $out['bytes'] === '' || strlen($out['bytes']) > self::SHOT_MAX_BYTES) {
            return ['error' => 'image too large after re-encoding'];
        }
        return $out;
    }

    /** True when the image is one flat colour (area-averaged 64×40 thumbnail, every pixel within ±3). */
    private static function isUniform(\GdImage $im): bool
    {
        $thumb = imagecreatetruecolor(64, 40);
        imagealphablending($thumb, false);
        imagecopyresampled($thumb, $im, 0, 0, 0, 0, 64, 40, imagesx($im), imagesy($im));
        $first = imagecolorat($thumb, 0, 0);
        $uniform = true;
        for ($y = 0; $y < 40 && $uniform; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $c = imagecolorat($thumb, $x, $y);
                foreach ([24, 16, 8, 0] as $shift) {
                    if (abs((($c >> $shift) & 0xFF) - (($first >> $shift) & 0xFF)) > 3) {
                        $uniform = false;
                        break 2;
                    }
                }
            }
        }
        imagedestroy($thumb);
        return $uniform;
    }

    /** Job description for a claimed run. */
    private static function job(int $runId, string $token, array $task): array
    {
        $checks = Pipeline::checks($task);
        $tests = is_array($checks['tests'] ?? null) && !empty($checks['tests']['cases']) ? $checks['tests'] : null;
        $timeout = 15000;
        if ($tests !== null) {
            $per = is_numeric($tests['timeout_ms'] ?? null) ? (int) $tests['timeout_ms'] : 2000;
            $timeout = (int) clamp(count((array) $tests['cases']) * max(100, $per) + 10000, 15000, 120000);
        }
        return [
            'id' => $runId,
            'lock_token' => $token,
            'output_type' => (string) $task['output_type'],
            'render_url' => self::renderUrl($runId, $token),
            'viewports' => self::VIEWPORTS,
            'checks' => is_array($checks['browser'] ?? null) ? array_values(array_filter($checks['browser'], 'is_array')) : [],
            'tests' => $tests,
            'timeout_ms' => $timeout,
            'task' => ['slug' => (string) $task['slug'], 'title' => L($task, 'title')],
        ];
    }

    /** [run row, null] when the body proves ownership of a verifying run, else [null, error]. */
    private static function ownedRun(array $body): array
    {
        $runId = is_numeric($body['run_id'] ?? null) ? (int) $body['run_id'] : 0;
        $token = is_string($body['lock_token'] ?? null) ? $body['lock_token'] : '';
        if ($runId <= 0 || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return [null, self::error('bad_request', 400)];
        }
        $run = DB::one('SELECT * FROM runs WHERE id = ?', [$runId]);
        if (!$run) {
            return [null, self::error('not_found', 404)];
        }
        if ($run['status'] !== 'verifying' || !is_string($run['lock_token']) || !hash_equals($run['lock_token'], $token)) {
            return [null, self::error('lease_lost', 409)];
        }
        return [$run, null];
    }

    private static function agent(mixed $v, string $default): string
    {
        return is_string($v) && in_array($v, self::AGENTS, true) ? $v : $default;
    }

    private static function error(string $code, int $http): array
    {
        return ['ok' => false, 'error' => $code, 'http' => $http];
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    private static function str(mixed $v, int $max): string
    {
        if (!is_scalar($v)) {
            return '';
        }
        $s = mb_convert_encoding((string) $v, 'UTF-8', 'UTF-8');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return trim(Pipeline::clip($s, $max));
    }
}
