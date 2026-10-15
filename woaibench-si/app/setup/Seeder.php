<?php
/**
 * Seeds reference data from sql/seed/: tracks.json, tasks/*.json, gpus.json.
 *
 * Idempotent upserts by natural key (track slug, task slug, GPU name); never deletes anything.
 * An existing row is updated only when the seed content differs AND
 *   ($updateExisting OR the row still holds exactly what the seeder last wrote — i.e. no admin edit since;
 *    tasks additionally need source = 'seed').
 * "What the seeder last wrote" is a content hash kept per row in settings 'seed_hashes'
 * ({"task:<slug>":"<sha256>", "track:<slug>":…, "gpu:<name>":…}), so admin edits are detected
 * without any cooperation from the admin module.
 */
declare(strict_types=1);

namespace WB\Setup;

defined('WB') || exit;

use DB;
use RuntimeException;
use Settings;

final class Seeder
{
    public const OUTPUT_TYPES = ['html', 'js', 'answer', 'agent', 'text'];
    private const HASH_KEY = 'seed_hashes';
    private const DIFFICULTY = ['easy' => 1, 'medium' => 2, 'hard' => 3, 'expert' => 4];

    /** Field => normalisation type. Only these fields are compared/updated on existing rows. */
    private const FIELDS = [
        'track' => [
            'name_ar' => 'str', 'name_en' => 'str', 'desc_ar' => 'str', 'desc_en' => 'str', 'icon' => 'str',
            'color' => 'str', 'sort' => 'int', 'auto_weight' => 'dec3',
        ],
        'task' => [
            'track_id' => 'int', 'title_ar' => 'str', 'title_en' => 'str', 'summary_ar' => 'str', 'summary_en' => 'str',
            'prompt' => 'str', 'output_type' => 'str', 'difficulty' => 'int', 'rubric_json' => 'json',
            'checks_json' => 'json', 'auto_weight' => 'dec3', 'visibility' => 'str',
        ],
        'gpu' => [
            'vendor' => 'str', 'kind' => 'str', 'vram_gb' => 'dec2', 'bandwidth_gbs' => 'dec2',
            'fp16_tflops' => 'dec2', 'sort' => 'int',
        ],
    ];

    private const TABLES = ['track' => ['tracks', 'slug'], 'task' => ['tasks', 'slug'], 'gpu' => ['gpus', 'name']];

    /**
     * Sync seed files into the database. Returns counts per kind
     * ({seed, inserted, updated, unchanged, skipped, invalid}), skipped keys, errors, files read, ms.
     */
    public static function run(bool $updateExisting = false): array
    {
        return self::execute($updateExisting, false);
    }

    /** Same as run() but writes nothing: shows what a sync would do. */
    public static function preview(bool $updateExisting = false): array
    {
        return self::execute($updateExisting, true);
    }

    /** Parse the seed files only (no database): {tracks, tasks, gpus, task_files, errors}. */
    public static function summary(): array
    {
        $errors = [];
        $tracks = self::loadTracks($errors);
        $tasks = self::loadTasks($errors, gmdate('Y.m'), $files);
        $gpus = self::loadGpus($errors);
        return ['tracks' => count($tracks), 'tasks' => count($tasks), 'gpus' => count($gpus), 'task_files' => $files, 'errors' => $errors];
    }

    private static function execute(bool $updateExisting, bool $dry): array
    {
        $t0 = microtime(true);
        $errors = [];
        $out = ['ok' => true, 'dry_run' => $dry, 'update_existing' => $updateExisting, 'skipped' => [], 'errors' => [], 'files' => 0];
        $locked = $dry ? null : self::lock();
        if ($locked === false) {
            throw new RuntimeException('Another seed sync is already running. Try again in a minute.');
        }
        try {
            $hashes = self::hashes();
            $tracks = self::loadTracks($errors);
            $out['tracks'] = self::sync('track', self::keyed($tracks, 'slug'), $updateExisting, $dry, $hashes, $out['skipped']);

            $tasks = self::loadTasks($errors, (string) Settings::get('season', gmdate('Y.m')), $out['files'], $entries);
            $out['tasks'] = self::sync('task', self::resolveTracks($tasks, $tracks, $errors), $updateExisting, $dry, $hashes, $out['skipped']);
            $out['tasks']['invalid'] = $entries - $out['tasks']['seed'];

            $gpus = self::loadGpus($errors);
            $out['gpus'] = self::sync('gpu', self::keyed($gpus, 'name'), $updateExisting, $dry, $hashes, $out['skipped']);

            if (!$dry) {
                self::saveHashes($hashes);
                if ($out['tasks']['inserted'] + $out['tasks']['updated'] > 0) {
                    Settings::set('aggregate_dirty', 1);
                }
                Settings::set('seed_last_sync', ['at' => now(), 'tracks' => $out['tracks'], 'tasks' => $out['tasks'], 'gpus' => $out['gpus']]);
            }
        } finally {
            if ($locked === true) {
                self::unlock();
            }
            Settings::flush();
        }
        $out['errors'] = $errors;
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $out;
    }

    /**
     * Insert/update one kind. $rows: key => db row (insert-only columns included).
     * @param array<string,string> $hashes  modified in place
     */
    private static function sync(string $kind, array $rows, bool $updateExisting, bool $dry, array &$hashes, array &$skipped): array
    {
        [$table, $keyCol] = self::TABLES[$kind];
        $counts = ['seed' => count($rows), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        if (!$rows) {
            return $counts;
        }
        $existing = [];
        foreach (DB::all("SELECT t.* FROM `$table` t") as $r) {
            $existing[mb_strtolower((string) $r[$keyCol])] = $r; // the column collation is case-insensitive
        }
        $work = static function () use ($kind, $rows, $table, $keyCol, $existing, $updateExisting, $dry, &$hashes, &$skipped, &$counts): void {
            foreach ($rows as $key => $row) {
                $hk = $kind . ':' . $key;
                $seedHash = self::hash($kind, $row);
                $cur = $existing[mb_strtolower((string) $key)] ?? null;
                if ($cur === null) {
                    if (!$dry) {
                        DB::insert($table, $row);
                    }
                    $hashes[$hk] = $seedHash;
                    $counts['inserted']++;
                    continue;
                }
                $dbHash = self::hash($kind, $cur);
                if ($dbHash === $seedHash) {
                    $hashes[$hk] = $seedHash; // adopt: the row matches the seed exactly
                    $counts['unchanged']++;
                    continue;
                }
                $pristine = ($hashes[$hk] ?? null) === $dbHash && ($kind !== 'task' || ($cur['source'] ?? '') === 'seed');
                if (!$updateExisting && !$pristine) {
                    $skipped[] = $hk;
                    $counts['skipped']++;
                    continue;
                }
                if (!$dry) {
                    $data = array_intersect_key($row, self::FIELDS[$kind]);
                    if ($kind === 'task') {
                        $data['source'] = 'seed';
                        $data['version'] = min(65535, (int) ($cur['version'] ?? 1) + 1);
                    }
                    DB::update($table, $data, "`$keyCol` = ?", [(string) $cur[$keyCol]]);
                }
                $hashes[$hk] = $seedHash;
                $counts['updated']++;
            }
        };
        $dry ? $work() : DB::tx($work);
        return $counts;
    }

    /** Content hash over the seed-controlled fields, normalised so a DB row and a seed row compare equal. */
    private static function hash(string $kind, array $row): string
    {
        $norm = [];
        foreach (self::FIELDS[$kind] as $field => $type) {
            $norm[$field] = self::norm($row[$field] ?? null, $type);
        }
        return hash('sha256', (string) json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function norm(mixed $v, string $type): mixed
    {
        if ($v === null) {
            return null;
        }
        return match ($type) {
            'int' => (int) $v,
            'dec2' => number_format((float) $v, 2, '.', ''),
            'dec3' => number_format((float) $v, 3, '.', ''),
            'json' => self::canonJson($v),
            default => (string) $v,
        };
    }

    /** Canonical JSON text; objects stay objects ({} vs [] matters for answer checks). */
    private static function canonJson(mixed $v): ?string
    {
        if (is_string($v)) {
            $decoded = json_decode($v);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $v;
            }
            $v = $decoded;
        }
        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return $json === false ? null : $json;
    }

    /* ------------------------------------------------------------------ loaders */

    private static function loadTracks(array &$errors): array
    {
        $out = [];
        foreach (self::readList(WB_ROOT . '/sql/seed/tracks.json', 'tracks', $errors) as $i => $t) {
            $slug = self::str($t, 'slug');
            $where = 'tracks.json #' . ($i + 1);
            if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,39}$/', $slug)) {
                $errors[] = "$where: invalid slug";
                continue;
            }
            $nameEn = self::str($t, 'name_en');
            $nameAr = self::str($t, 'name_ar') ?: $nameEn;
            if ($nameEn === '' || mb_strlen($nameEn) > 120 || mb_strlen($nameAr) > 120) {
                $errors[] = "$where ($slug): name_en/name_ar missing or longer than 120 characters";
                continue;
            }
            $color = self::str($t, 'color');
            $out[] = [
                'slug' => $slug, 'name_ar' => $nameAr, 'name_en' => $nameEn,
                'desc_ar' => self::str($t, 'desc_ar') ?: null, 'desc_en' => self::str($t, 'desc_en') ?: null,
                'icon' => substr(self::str($t, 'icon') ?: 'layers', 0, 40),
                'color' => preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color) ? $color : '#D9B26A',
                'sort' => (int) ($t['sort'] ?? ($i + 1)),
                'auto_weight' => self::weight($t['auto_weight'] ?? 0.4) ?? 0.4,
                'is_active' => 1,
            ];
        }
        return $out;
    }

    /** $files / $entries receive the number of task files and task entries read. */
    private static function loadTasks(array &$errors, string $season, ?int &$files = 0, ?int &$entries = 0): array
    {
        $paths = glob(WB_ROOT . '/sql/seed/tasks/*.json') ?: [];
        sort($paths, SORT_STRING);
        $files = count($paths);
        $entries = 0;
        $out = [];
        $seen = [];
        foreach ($paths as $path) {
            $file = basename($path);
            foreach (self::readList($path, 'tasks', $errors) as $i => $t) {
                $entries++;
                $row = self::taskRow($t, $season, $err);
                if ($row === null) {
                    $errors[] = $file . ' #' . ($i + 1) . (isset($t['slug']) && is_string($t['slug']) ? ' (' . $t['slug'] . ')' : '') . ': ' . $err;
                    continue;
                }
                if (isset($seen[$row['slug']])) {
                    $errors[] = "$file: duplicate slug {$row['slug']} (already defined in {$seen[$row['slug']]})";
                    continue;
                }
                $seen[$row['slug']] = $file;
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Map one seed task to a tasks row (with 'track_slug'); null + $err when invalid. */
    private static function taskRow(array $t, string $season, ?string &$err): ?array
    {
        $err = null;
        $slug = self::str($t, 'slug');
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,119}$/', $slug)) {
            $err = 'missing or invalid slug (lowercase letters, digits, - and _ only)';
            return null;
        }
        $track = self::str($t, 'track') ?: self::str($t, 'track_slug');
        [$titleAr, $titleEn] = self::pair($t, 'title');
        [$sumAr, $sumEn] = self::pair($t, 'summary');
        $prompt = $t['prompt'] ?? '';
        if (is_array($prompt)) {
            $prompt = implode("\n", array_map('strval', array_filter($prompt, 'is_scalar')));
        }
        $type = self::str($t, 'output_type') ?: 'html';
        $diff = $t['difficulty'] ?? 2;
        $diff = is_string($diff) && isset(self::DIFFICULTY[strtolower($diff)]) ? self::DIFFICULTY[strtolower($diff)] : (int) $diff;
        $vis = self::str($t, 'visibility') ?: 'public';
        $rubric = self::jsonField($t, 'rubric');
        $checks = self::jsonField($t, 'checks');
        $err = match (true) {
            $track === '' => 'missing track',
            $titleEn === '' && $titleAr === '' => 'missing title',
            mb_strlen($titleEn) > 200 || mb_strlen($titleAr) > 200 => 'title longer than 200 characters',
            !is_string($prompt) || trim($prompt) === '' => 'missing prompt',
            !in_array($type, self::OUTPUT_TYPES, true) => 'invalid output_type "' . $type . '"',
            !in_array($vis, ['public', 'holdout'], true) => 'invalid visibility "' . $vis . '"',
            $rubric === false || ($rubric !== null && !array_is_list((array) $rubric)) => 'rubric must be a JSON array of criteria',
            $checks === false || ($checks !== null && !is_object($checks) && !(is_array($checks) && $checks === [])) => 'checks must be a JSON object',
            default => null,
        };
        if ($err !== null) {
            return null;
        }
        return [
            'slug' => $slug, 'track_slug' => $track,
            'title_ar' => $titleAr ?: $titleEn, 'title_en' => $titleEn ?: $titleAr,
            'summary_ar' => ($sumAr ?: $sumEn) ?: null, 'summary_en' => ($sumEn ?: $sumAr) ?: null,
            'prompt' => (string) $prompt, 'output_type' => $type, 'difficulty' => max(1, min(4, $diff)),
            'rubric_json' => $rubric === null ? null : self::canonJson($rubric),
            'checks_json' => $checks === null ? null : self::canonJson($checks),
            'auto_weight' => self::weight($t['auto_weight'] ?? null),
            'visibility' => $vis,
            'is_active' => array_key_exists('is_active', $t) ? (int) (bool) $t['is_active'] : (array_key_exists('active', $t) ? (int) (bool) $t['active'] : 1),
            'version' => 1, 'season' => $season, 'source' => 'seed',
        ];
    }

    private static function loadGpus(array &$errors): array
    {
        $path = WB_ROOT . '/sql/seed/gpus.json';
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (self::readList($path, 'gpus', $errors) as $i => $g) {
            $name = self::str($g, 'name');
            $vram = $g['vram_gb'] ?? $g['vram'] ?? null;
            $bw = $g['bandwidth_gbs'] ?? $g['bandwidth'] ?? null;
            if ($name === '' || mb_strlen($name) > 120 || !is_numeric($vram) || !is_numeric($bw)) {
                $errors[] = 'gpus.json #' . ($i + 1) . ': name, vram_gb and bandwidth_gbs are required';
                continue;
            }
            $tf = $g['fp16_tflops'] ?? $g['tflops'] ?? null;
            $out[] = [
                'name' => $name, 'vendor' => substr(self::str($g, 'vendor') ?: 'Generic', 0, 40),
                'kind' => in_array($g['kind'] ?? 'gpu', ['gpu', 'apple', 'cpu'], true) ? $g['kind'] ?? 'gpu' : 'gpu',
                'vram_gb' => (float) $vram, 'bandwidth_gbs' => (float) $bw,
                'fp16_tflops' => is_numeric($tf) ? (float) $tf : null,
                'sort' => (int) ($g['sort'] ?? ($i + 1)),
            ];
        }
        return $out;
    }

    /**
     * Read a seed file holding a list, {"<wrapKey>": [...]} or a single object with a "slug"/"name".
     * Nested objects are kept as stdClass so JSON objects survive re-encoding.
     */
    private static function readList(string $path, string $wrapKey, array &$errors): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode(str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = basename($path) . ': invalid JSON (' . json_last_error_msg() . ')';
            return [];
        }
        if (is_object($data) && isset($data->{$wrapKey}) && is_array($data->{$wrapKey})) {
            $data = $data->{$wrapKey};
        } elseif (is_object($data) && (isset($data->slug) || isset($data->name))) {
            $data = [$data];
        }
        if (!is_array($data)) {
            $errors[] = basename($path) . ': expected a JSON array of objects';
            return [];
        }
        $out = [];
        foreach ($data as $item) {
            if (is_object($item)) {
                $out[] = get_object_vars($item);
            }
        }
        return $out;
    }

    /** Attach track_id to tasks; tasks pointing at unknown tracks are reported and dropped. */
    private static function resolveTracks(array $tasks, array $seedTracks, array &$errors): array
    {
        $ids = [];
        foreach (DB::all('SELECT id, slug FROM tracks') as $r) {
            $ids[$r['slug']] = (int) $r['id'];
        }
        foreach ($seedTracks as $t) {
            $ids[$t['slug']] ??= -1; // dry run: track will be inserted by this sync
        }
        $out = [];
        foreach ($tasks as $t) {
            if (!isset($ids[$t['track_slug']])) {
                $errors[] = "task {$t['slug']}: unknown track \"{$t['track_slug']}\"";
                continue;
            }
            $t['track_id'] = $ids[$t['track_slug']];
            unset($t['track_slug']);
            $out[$t['slug']] = $t;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ small helpers */

    private static function keyed(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r[$key]] = $r;
        }
        return $out;
    }

    private static function str(array $a, string $k): string
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
    }

    /** [ar, en] from title_ar/title_en, or {"title":{"ar":…,"en":…}}, or a plain "title". */
    private static function pair(array $t, string $field): array
    {
        $ar = self::str($t, $field . '_ar');
        $en = self::str($t, $field . '_en');
        $v = $t[$field] ?? null;
        if (is_object($v)) {
            $ar = $ar ?: trim((string) ($v->ar ?? ''));
            $en = $en ?: trim((string) ($v->en ?? ''));
        } elseif (is_string($v)) {
            $en = $en ?: trim($v);
        }
        return [$ar, $en];
    }

    /** Value of "<f>" or "<f>_json" (object/array or JSON text). null = absent, false = invalid. */
    private static function jsonField(array $t, string $f): mixed
    {
        $v = $t[$f] ?? $t[$f . '_json'] ?? null;
        if (is_string($v)) {
            $v = trim($v) === '' ? null : json_decode($v);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
        }
        return $v;
    }

    private static function weight(mixed $v): ?float
    {
        return is_numeric($v) ? round(max(0.0, min(1.0, (float) $v)), 3) : null;
    }

    private static function hashes(): array
    {
        $v = DB::val('SELECT v FROM settings WHERE k = ?', [self::HASH_KEY]);
        $data = is_string($v) ? json_decode($v, true) : null;
        return is_array($data) ? $data : [];
    }

    private static function saveHashes(array $hashes): void
    {
        ksort($hashes, SORT_STRING);
        DB::upsert('settings', ['k' => self::HASH_KEY, 'v' => to_json($hashes)], ['v']);
    }

    private static function lock(): ?bool
    {
        try {
            $v = DB::val("SELECT GET_LOCK('wb_seed', 10)");
            return $v === null ? null : (int) $v === 1;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function unlock(): void
    {
        try {
            DB::val("SELECT RELEASE_LOCK('wb_seed')");
        } catch (\Throwable $e) {
            // released with the connection anyway
        }
    }
}
