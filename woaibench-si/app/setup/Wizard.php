<?php
/**
 * Web installer UI (install/index.php): a 4-step wizard + "done" and "locked" screens.
 * State lives in its own session ('wb_install'); secrets (DB password, API key) stay there only until
 * the config file is written and are never rendered back into HTML.
 */
declare(strict_types=1);

namespace WB\Setup;

defined('WB') || exit;

use Csrf;
use Http;
use I18n;

final class Wizard
{
    public const STEPS = [1 => 'setup.step1', 2 => 'setup.step2', 3 => 'setup.step3', 4 => 'setup.step4'];
    private const KEY_TESTS_PER_MIN = 6;
    private const DB_TESTS_PER_10MIN = 20;

    /** Entry point used by install/index.php. */
    public static function handle(): void
    {
        set_exception_handler([self::class, 'fail']);
        self::startSession();
        self::sendHeaders();
        I18n::setLang(self::lang());
        $step = self::requestedStep();

        $reason = self::lockReason();
        if ($reason !== null) {
            if ($reason !== 'broken') {
                Installer::removeSetupToken();
            }
            if ($step === 'done' && !empty($_SESSION['wbi']['done'])) {
                self::render('done', ['r' => $_SESSION['wbi']['done']], 'done');
                return;
            }
            if (is_post()) {
                self::refuse();
            }
            self::render('locked', ['reason' => $reason], 'locked');
            return;
        }
        Installer::ensureSetupToken();
        if (is_post()) {
            self::handlePost();
            return;
        }
        self::show($step);
    }

    /** Last-resort error page (also used for uncaught exceptions). */
    public static function fail(\Throwable $e): void
    {
        log_error('Installer: ' . get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
        if (!headers_sent()) {
            http_response_code(500);
        }
        try {
            I18n::setLang(I18n::lang());
            self::render('error', ['message' => __('setup.err_server')], 'error');
        } catch (\Throwable $inner) {
            echo '<!doctype html><meta charset="utf-8"><title>Installer error</title><p>Installer error. See storage/logs.</p>';
        }
    }

    /* ------------------------------------------------------------------ GET screens */

    private static function show(int|string $step): void
    {
        $S = &$_SESSION['wbi'];
        $checks = Installer::requirements();
        $reqOk = Installer::requirementsPass($checks);
        if ($step === 'done') {
            $step = 1;
        }
        if ($step >= 2 && empty($S['token_ok'])) {
            self::flash('error', __('setup.err_token_required'));
            self::go(1);
        }
        if ($step >= 2 && !$reqOk) {
            self::flash('error', __('setup.err_requirements'));
            self::go(1);
        }
        if ($step >= 3 && empty($S['db'])) {
            self::go(2);
        }
        if ($step >= 4 && (empty($S['site']) || empty($S['admin']))) {
            self::go(3);
        }
        match ($step) {
            1 => self::render('step-1', [
                'checks' => $checks, 'pass' => $reqOk, 'token_ok' => !empty($S['token_ok']),
                'token_file' => Installer::setupToken() !== '' ? 'storage/cache/setup-token.txt' : null,
            ], 1),
            2 => self::render('step-2', self::dbVars(), 2),
            3 => self::render('step-3', self::siteVars(), 3),
            default => self::render('step-4', self::reviewVars(), 4),
        };
        unset($S['old'], $S['errors'], $S['db_error'], $S['key_result']);
    }

    private static function dbVars(): array
    {
        $S = $_SESSION['wbi'];
        $v = $S['old']['db'] ?? $S['db'] ?? [];
        return [
            'v' => [
                'host' => $v['host'] ?? 'localhost', 'port' => $v['port'] ?? 3306,
                'name' => $v['name'] ?? '', 'user' => $v['user'] ?? '',
            ],
            'has_pass' => !empty($S['db']['pass']),
            'error' => $S['db_error'] ?? null,
            'info' => $S['db_info'] ?? null,
        ];
    }

    private static function siteVars(): array
    {
        $S = $_SESSION['wbi'];
        $site = $S['old']['site'] ?? $S['site'] ?? [];
        $admin = $S['old']['admin'] ?? $S['admin'] ?? [];
        $key = (string) ($S['key'] ?? '');
        return [
            'v' => [
                'url' => $site['url'] ?? site_origin() . base_path(),
                'name' => $site['name'] ?? 'WOAIBench',
                'lang' => $site['lang'] ?? 'ar',
                'admin_name' => $admin['name'] ?? '',
                'admin_email' => $admin['email'] ?? '',
            ],
            'errors' => $S['errors'] ?? [],
            'has_hash' => !empty($S['admin']['hash']),
            'key_tail' => $key !== '' ? substr($key, -4) : '',
            'key_result' => $S['key_result'] ?? null,
            'db_info' => $S['db_info'] ?? null,
        ];
    }

    private static function reviewVars(): array
    {
        $S = $_SESSION['wbi'];
        $key = (string) ($S['key'] ?? '');
        return [
            'db' => array_diff_key($S['db'], ['pass' => 1]),
            'db_info' => $S['db_info'] ?? [],
            'site' => $S['site'],
            'admin' => ['name' => $S['admin']['name'], 'email' => $S['admin']['email']],
            'key_tail' => $key !== '' ? substr($key, -4) : '',
            'seed' => Seeder::summary(),
        ];
    }

    /* ------------------------------------------------------------------ POST actions */

    private static function handlePost(): void
    {
        $action = (string) ($_POST['action'] ?? '');
        if (!Csrf::valid()) {
            if ($action === 'test_key') {
                json_out(['ok' => false, 'message' => __('setup.err_csrf')], 419);
            }
            self::flash('error', __('setup.err_csrf'));
            self::go(self::requestedStep());
        }
        if ($action === 'token') {
            self::postToken();
        }
        if (empty($_SESSION['wbi']['token_ok']) && $action !== 'restart') {
            if ($action === 'test_key') {
                json_out(['ok' => false, 'message' => __('setup.err_token_required')], 403);
            }
            self::flash('error', __('setup.err_token_required'));
            self::go(1);
        }
        match ($action) {
            'db' => self::postDb(),
            'site' => self::postSite(),
            'test_key' => self::postTestKey(),
            'install' => self::postInstall(),
            'restart' => self::postRestart(),
            default => self::go(1),
        };
    }

    /** Step 1: prove access to the hosting files by pasting storage/cache/setup-token.txt. */
    private static function postToken(): never
    {
        if (!self::throttle('token', 10, 600)) {
            self::flash('error', __('setup.err_throttled'));
            self::go(1);
        }
        if (!Installer::setupTokenValid((string) ($_POST['setup_token'] ?? ''))) {
            self::flash('error', __('setup.err_token'));
            self::go(1);
        }
        session_regenerate_id(true);
        $_SESSION['wbi']['token_ok'] = true;
        self::go(Installer::requirementsPass(Installer::requirements()) ? 2 : 1);
    }

    private static function postDb(): never
    {
        $S = &$_SESSION['wbi'];
        if (!Installer::requirementsPass(Installer::requirements())) {
            self::flash('error', __('setup.err_requirements'));
            self::go(1);
        }
        $db = [
            'host' => trim((string) ($_POST['db_host'] ?? '')),
            'port' => input_int('db_port', 3306, 1, 65535),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
        ];
        if ($db['pass'] === '' && !empty($S['db']['pass'])) {
            $db['pass'] = $S['db']['pass']; // "leave empty to keep"
        }
        $S['old']['db'] = array_diff_key($db, ['pass' => 1]);
        if (!preg_match('/^[A-Za-z0-9._\-:\[\]]{1,190}$/', $db['host']) || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $db['name'])
            || !preg_match('/^[^\x00-\x1F;]{1,80}$/', $db['user'])) {
            $S['db_error'] = ['key' => 'setup.err_db_fields', 'params' => [], 'detail' => null];
            $S['db'] = null;
            self::go(2);
        }
        if (!self::throttle('db', self::DB_TESTS_PER_10MIN, 600)) {
            $S['db_error'] = ['key' => 'setup.err_throttled', 'params' => [], 'detail' => null];
            $S['db'] = null;
            self::go(2);
        }
        $res = Installer::testDb($db);
        if (!$res['ok']) {
            $S['db_error'] = ['key' => $res['error'], 'params' => $res['params'], 'detail' => $res['detail']];
            $S['db'] = null;
            self::go(2);
        }
        $S['db'] = $db;
        $S['db_info'] = ['server' => $res['server'], 'existing_tables' => $res['existing_tables'], 'admins' => $res['admins']];
        unset($S['old']);
        self::go(3);
    }

    private static function postSite(): never
    {
        $S = &$_SESSION['wbi'];
        $site = [
            'url' => rtrim(trim((string) ($_POST['site_url'] ?? '')), '/'),
            'name' => trim((string) ($_POST['site_name'] ?? '')),
            'lang' => ($_POST['default_lang'] ?? '') === 'en' ? 'en' : 'ar',
        ];
        $admin = [
            'name' => trim((string) ($_POST['admin_name'] ?? '')),
            'email' => strtolower(trim((string) ($_POST['admin_email'] ?? ''))),
        ];
        $pass = (string) ($_POST['admin_pass'] ?? '');
        $pass2 = (string) ($_POST['admin_pass2'] ?? '');
        $key = trim((string) ($_POST['openrouter_key'] ?? ''));
        if ($key !== '' && self::keyFormatOk($key)) {
            $S['key'] = $key; // only well-formed keys are remembered (and later written to the config)
        }
        $S['old'] = ['site' => $site, 'admin' => $admin];

        if (!empty($_POST['do_test'])) { // no-JS "Test key" button
            $S['key_result'] = self::keyTest($key !== '' ? $key : (string) ($S['key'] ?? ''));
            self::go(3);
        }
        $errors = self::validateSite($site, $admin, $pass, $pass2, $key);
        if ($errors) {
            $S['errors'] = $errors;
            self::go(3);
        }
        $keepHash = $pass === '' && !empty($S['admin']['hash']) && ($S['admin']['email'] ?? '') === $admin['email'];
        $S['site'] = $site;
        $S['admin'] = $admin + ['hash' => $keepHash ? $S['admin']['hash'] : password_hash($pass, PASSWORD_DEFAULT)];
        unset($S['old']);
        self::go(4);
    }

    /** Field errors as field => lang key. */
    private static function validateSite(array $site, array $admin, string $pass, string $pass2, string $key): array
    {
        $S = $_SESSION['wbi'];
        $e = [];
        $u = parse_url($site['url']);
        if (!filter_var($site['url'], FILTER_VALIDATE_URL) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)
            || empty($u['host']) || isset($u['query']) || isset($u['fragment']) || isset($u['user']) || strlen($site['url']) > 190) {
            $e['site_url'] = 'setup.err_url';
        }
        if ($site['name'] === '' || mb_strlen($site['name']) > 60) {
            $e['site_name'] = 'setup.err_site_name';
        }
        if ($admin['name'] === '' || mb_strlen($admin['name']) > 120) {
            $e['admin_name'] = 'setup.err_admin_name';
        }
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL) || strlen($admin['email']) > 190) {
            $e['admin_email'] = 'setup.err_email';
        }
        $keepHash = $pass === '' && $pass2 === '' && !empty($S['admin']['hash']) && ($S['admin']['email'] ?? '') === $admin['email'];
        if (!$keepHash) {
            if (mb_strlen($pass) < 12 || mb_strlen($pass) > 200) {
                $e['admin_pass'] = 'setup.err_pass_short';
            } elseif (self::predictable($pass, $admin['email'], $site['name'])) {
                $e['admin_pass'] = 'setup.err_pass_weak';
            } elseif (!hash_equals($pass, $pass2)) {
                $e['admin_pass2'] = 'setup.err_pass_match';
            }
        }
        if ($key !== '' && !self::keyFormatOk($key)) {
            $e['openrouter_key'] = 'setup.api_format';
        }
        return $e;
    }

    /** Printable ASCII without spaces, 10–200 characters (OpenRouter keys look like sk-or-v1-<hex>). */
    private static function keyFormatOk(string $key): bool
    {
        return (bool) preg_match('/^[\x21-\x7E]{10,200}$/', $key);
    }

    /** Rejects passwords with < 5 distinct characters, or built around the email name / site name. */
    private static function predictable(string $pass, string $email, string $siteName): bool
    {
        $p = mb_strtolower($pass);
        if (count(array_unique(mb_str_split($p))) < 5) {
            return true;
        }
        $local = mb_strtolower(explode('@', $email)[0]);
        foreach ([$local, mb_strtolower($siteName), 'password', '123456', 'qwerty', 'woaibench'] as $word) {
            // A known word plus a few characters is not a passphrase.
            if (mb_strlen($word) >= 5 && str_contains($p, $word) && mb_strlen($p) - mb_strlen($word) < 8) {
                return true;
            }
        }
        return false;
    }

    private static function postTestKey(): never
    {
        $S = &$_SESSION['wbi'];
        $key = trim((string) ($_POST['openrouter_key'] ?? '')) ?: (string) ($S['key'] ?? '');
        $res = self::keyTest($key);
        json_out(['ok' => $res['ok'], 'state' => $res['state'], 'message' => $res['message']]);
    }

    /** Run (rate-limited) key test; returns {ok, state, message (translated)}. */
    private static function keyTest(string $key): array
    {
        $S = &$_SESSION['wbi'];
        if ($key === '') {
            return ['ok' => false, 'state' => 'empty', 'message' => __('setup.api_empty')];
        }
        if (!self::keyFormatOk($key)) {
            return ['ok' => false, 'state' => 'format', 'message' => __('setup.api_format')];
        }
        $recent = array_filter($S['key_tests'] ?? [], static fn($t) => $t > time() - 60);
        if (count($recent) >= self::KEY_TESTS_PER_MIN || !self::throttle('key', 30, 600)) {
            return ['ok' => false, 'state' => 'throttled', 'message' => __('setup.api_throttled')];
        }
        $recent[] = time();
        $S['key_tests'] = array_values($recent);
        $r = Installer::testOpenRouter($key);
        return ['ok' => $r['ok'], 'state' => $r['state'], 'message' => __($r['message'], $r['params'])];
    }

    private static function postInstall(): never
    {
        $S = &$_SESSION['wbi'];
        if (empty($S['db']) || empty($S['site']) || empty($S['admin'])) {
            self::go(empty($S['db']) ? 2 : 3);
        }
        try {
            $result = Installer::install([
                'db' => $S['db'], 'site' => $S['site'], 'admin' => $S['admin'], 'openrouter_key' => (string) ($S['key'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $msg = str_replace([(string) $S['db']['pass'], (string) ($S['key'] ?? "\0")], '********', $e->getMessage());
            log_error('Installer: install failed: ' . $msg, ['file' => $e->getFile() . ':' . $e->getLine()]);
            self::flash('error', $msg === 'config_write' ? __('setup.err_config_write') : __('setup.err_install', ['error' => mb_substr($msg, 0, 300)]));
            self::go(4);
        }
        // Forget every secret; keep only the non-secret summary for the "done" screen.
        $lang = $S['lang'] ?? 'ar';
        $_SESSION['wbi'] = ['lang' => $lang, 'done' => $result + ['cron' => Installer::cronCommand(), 'web_cron' => self::webCronUrl()]];
        session_regenerate_id(true);
        self::go('done');
    }

    private static function postRestart(): never
    {
        $_SESSION['wbi'] = ['lang' => $_SESSION['wbi']['lang'] ?? 'ar', 'token_ok' => !empty($_SESSION['wbi']['token_ok'])];
        self::go(1);
    }

    /* ------------------------------------------------------------------ plumbing */

    private static function startSession(): void
    {
        session_name('wb_install');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path() . '/install',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $dir = WB_STORAGE . '/sessions';
        if ((is_dir($dir) || @mkdir($dir, 0770, true)) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_start();
        if (!isset($_SESSION['wbi']) || !is_array($_SESSION['wbi'])) {
            $_SESSION['wbi'] = [];
        }
    }

    private static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $n = Http::nonce();
        header_remove('X-Powered-By');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$n}'; style-src 'self' 'nonce-{$n}'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
    }

    private static function lang(): string
    {
        $q = $_GET['lang'] ?? null;
        if (is_string($q) && in_array($q, I18n::LANGS, true)) {
            $_SESSION['wbi']['lang'] = $q;
        }
        $l = $_SESSION['wbi']['lang'] ?? 'ar';
        return in_array($l, I18n::LANGS, true) ? $l : 'ar';
    }

    private static function requestedStep(): int|string
    {
        $s = $_GET['step'] ?? '1';
        if ($s === 'done') {
            return 'done';
        }
        return is_numeric($s) ? max(1, min(4, (int) $s)) : 1;
    }

    /** null when the installer may run, else 'lock' | 'config' | 'broken'. */
    private static function lockReason(): ?string
    {
        if (Installer::isLocked()) {
            return 'lock';
        }
        return match (Installer::configState()) {
            'working' => 'config',
            'broken' => 'broken',
            default => null,
        };
    }

    private static function refuse(): never
    {
        if (($_POST['action'] ?? '') === 'test_key') {
            json_out(['ok' => false, 'message' => __('setup.locked_title')], 403);
        }
        http_response_code(403);
        self::render('locked', ['reason' => self::lockReason() ?? 'lock'], 'locked');
        exit;
    }

    public static function url(int|string $step, array $extra = []): string
    {
        return base_path() . '/install/?' . http_build_query(['step' => $step] + $extra);
    }

    private static function go(int|string $step): never
    {
        header('Location: ' . self::url($step), true, 303);
        exit;
    }

    private static function flash(string $type, string $msg): void
    {
        $_SESSION['wbi']['flash'][] = ['type' => $type, 'message' => $msg];
    }

    /**
     * Per-IP attempt limit kept in storage/cache (the database is not available yet).
     * Returns false when $max attempts were already made in the last $window seconds.
     */
    private static function throttle(string $bucket, int $max, int $window): bool
    {
        $file = WB_STORAGE . '/cache/installer-throttle.json';
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return true; // fail open: storage is checked in step 1, and the installer locks itself soon
        }
        $lock = function_exists('flock'); // may be disabled on shared hosts (undefined → fatal in PHP 8)
        try {
            if ($lock) {
                flock($fh, LOCK_EX);
            }
            $data = json_decode((string) stream_get_contents($fh), true);
            $data = is_array($data) ? $data : [];
            $id = $bucket . ':' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|wb-installer');
            $now = time();
            foreach ($data as $k => $hits) {
                $data[$k] = array_values(array_filter((array) $hits, static fn($t) => is_int($t) && $t > $now - $window));
                if (!$data[$k]) {
                    unset($data[$k]);
                }
            }
            $allowed = count($data[$id] ?? []) < $max;
            if ($allowed) {
                $data[$id][] = $now;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));
            return $allowed;
        } finally {
            if ($lock) {
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
    }

    private static function webCronUrl(): string
    {
        $key = (string) \Config::get('cron_key', '');
        return $key === '' ? '' : rtrim((string) \Config::get('site_url', site_origin()), '/') . '/worker/tick?key=' . rawurlencode($key);
    }

    /** Render a partial from app/setup/views (used by the views themselves). */
    public static function partial(string $view, array $vars = []): string
    {
        return self::capture($view, $vars);
    }

    /** Render a view from app/setup/views inside the installer shell. */
    private static function render(string $view, array $vars, int|string $step): void
    {
        $flashes = $_SESSION['wbi']['flash'] ?? [];
        unset($_SESSION['wbi']['flash']);
        $content = self::capture($view, $vars + ['flashes' => $flashes, 'step' => $step]);
        echo self::capture('layout', ['content' => $content, 'step' => $step, 'view' => $view]);
    }

    private static function capture(string $view, array $vars): string
    {
        $file = __DIR__ . '/views/' . $view . '.php';
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
