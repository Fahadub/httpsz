<?php
/**
 * Applies the database schema idempotently.
 *
 *   1. sql/schema.sql, then every sql/schema-*.sql in name order — re-applied on every run
 *      (they must be idempotent: CREATE TABLE IF NOT EXISTS …; "already exists" errors are tolerated).
 *   2. sql/migrations/NNN_name.sql — each applied exactly once, in numeric order, and recorded.
 *   3. PHP upgrade steps (self::STEPS: data/file fixes SQL cannot do) — each applied once and recorded.
 *
 * State lives in settings 'schema_version':
 *   {"app":"1.0.0","migrations":["001_add_x.sql"],"steps":["shots_unguessable"],"files":{"schema.sql":"<sha1>"},…}
 */
declare(strict_types=1);

namespace WB\Setup;

defined('WB') || exit;

use DB;
use PDO;
use PDOException;
use RuntimeException;
use Settings;

final class Migrator
{
    /** MySQL/MariaDB errors that mean "this statement was already applied". */
    private const IGNORABLE = [
        1050, // table already exists
        1060, // duplicate column name
        1061, // duplicate key name
        1062, // duplicate entry (re-running an INSERT)
        1068, // multiple primary key defined
        1091, // can't drop field or key; check that it exists
        1826, // duplicate foreign key constraint name
    ];

    private const MIGRATION_RE = '/^(\d{3,})_[A-Za-z0-9_\-]+\.sql$/';

    /** One-off PHP upgrade steps: name => method of this class (PDO $pdo): array. Applied in this order. */
    private const STEPS = [
        'shots_unguessable' => 'renameGuessableShots',
    ];

    /** Legacy screenshot names (run_<id>_<viewport>.<ext>) that anyone could guess from a run id. */
    private const LEGACY_SHOT_RE = '/^run_(\d+)_(desktop|mobile)\.(png|webp|jpe?g)$/';

    /**
     * Apply schema files and pending migrations.
     * Returns {ok, files:[{file,statements,skipped}], migrations:[names applied now], schema_version, error, ms}.
     */
    public static function run(?PDO $pdo = null): array
    {
        $pdo ??= DB::pdo();
        $t0 = microtime(true);
        $out = ['ok' => true, 'files' => [], 'migrations' => [], 'steps' => [], 'schema_version' => null, 'error' => null, 'ms' => 0];
        $locked = self::lock($pdo);
        try {
            if ($locked === false) {
                throw new RuntimeException('Another migration is already running. Try again in a minute.');
            }
            foreach (self::schemaFiles() as $file) {
                $out['files'][] = self::applyFile($pdo, $file);
            }
            $state = self::state($pdo);
            foreach (self::pendingFrom($state) as $name => $file) {
                $out['files'][] = self::applyFile($pdo, $file);
                $state['migrations'][] = $name;
                $out['migrations'][] = $name;
                self::saveState($pdo, $state); // record each one immediately: a later failure must not re-run it
            }
            foreach (self::pendingSteps($state) as $name => $method) {
                $out['steps'][$name] = self::$method($pdo);
                $state['steps'][] = $name;
                self::saveState($pdo, $state);
            }
            $state['app'] = WB_VERSION;
            $state['files'] = self::fileHashes();
            $state['applied_at'] = gmdate('Y-m-d H:i:s');
            self::saveState($pdo, $state);
            $out['schema_version'] = $state;
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['error'] = $e->getMessage();
            log_error('Migrator failed: ' . $e->getMessage());
        } finally {
            if ($locked === true) {
                self::unlock($pdo);
            }
        }
        Settings::flush();
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** Current state + pending migrations (no changes). */
    public static function status(?PDO $pdo = null): array
    {
        $pdo ??= DB::pdo();
        $state = self::state($pdo);
        $hashes = self::fileHashes();
        return [
            'app_version' => WB_VERSION,
            'schema_version' => $state,
            'pending' => array_keys(self::pendingFrom($state)),
            'pending_steps' => array_keys(self::pendingSteps($state)),
            'schema_changed' => ($state['files'] ?? []) !== $hashes,
            'needs_upgrade' => ($state['app'] ?? null) !== WB_VERSION || self::pendingFrom($state) !== []
                || self::pendingSteps($state) !== [] || ($state['files'] ?? []) !== $hashes,
        ];
    }

    /** Schema files in apply order: schema.sql first, then schema-*.sql by name. */
    public static function schemaFiles(): array
    {
        $files = [];
        $main = WB_ROOT . '/sql/schema.sql';
        if (is_file($main)) {
            $files[] = $main;
        }
        $extra = glob(WB_ROOT . '/sql/schema-*.sql') ?: [];
        sort($extra, SORT_STRING);
        return array_merge($files, $extra);
    }

    /** Migration files keyed by file name, in numeric order. */
    public static function migrationFiles(): array
    {
        $out = [];
        foreach (glob(WB_ROOT . '/sql/migrations/*.sql') ?: [] as $file) {
            $name = basename($file);
            if (preg_match(self::MIGRATION_RE, $name)) {
                $out[$name] = $file;
            }
        }
        uksort($out, static fn(string $a, string $b): int => [(int) $a, $a] <=> [(int) $b, $b]);
        return $out;
    }

    /**
     * Split an SQL script into statements. Understands quotes, backticks, -- / # / block comments
     * (MySQL executable comments "/*! … *\/" are kept). DELIMITER blocks are not supported.
     */
    public static function split(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($c === "'" || $c === '"' || $c === '`') {
                $end = self::quotedEnd($sql, $i, $c);
                $buf .= substr($sql, $i, $end - $i + 1);
                $i = $end;
                continue;
            }
            if (($c === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n"], true)) || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $close = $close === false ? $len : $close + 1;
                if (($sql[$i + 2] ?? '') === '!') {
                    $buf .= substr($sql, $i, $close - $i + 1);
                } else {
                    $buf .= ' ';
                }
                $i = $close;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $stmts[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $stmts[] = trim($buf);
        }
        return $stmts;
    }

    /** Index of the closing quote that matches the one at $start (handles \\ escapes and doubled quotes). */
    private static function quotedEnd(string $sql, int $start, string $q): int
    {
        $len = strlen($sql);
        for ($j = $start + 1; $j < $len; $j++) {
            $ch = $sql[$j];
            if ($ch === '\\' && $q !== '`') {
                $j++;
                continue;
            }
            if ($ch === $q) {
                if (($sql[$j + 1] ?? '') === $q) {
                    $j++;
                    continue;
                }
                return $j;
            }
        }
        return $len - 1;
    }

    private static function applyFile(PDO $pdo, string $file): array
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Cannot read ' . basename($file));
        }
        $done = 0;
        $skipped = 0;
        foreach (self::split($sql) as $stmt) {
            try {
                $pdo->exec($stmt);
                $done++;
            } catch (PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (!in_array($code, self::IGNORABLE, true)) {
                    throw new RuntimeException(basename($file) . ': ' . $e->getMessage() . ' — in: ' . mb_substr(preg_replace('/\s+/', ' ', $stmt) ?? '', 0, 160), 0, $e);
                }
                $skipped++;
            }
        }
        return ['file' => self::relative($file), 'statements' => $done, 'skipped' => $skipped];
    }

    private static function pendingFrom(array $state): array
    {
        $applied = array_flip($state['migrations'] ?? []);
        return array_filter(self::migrationFiles(), static fn($f, $name) => !isset($applied[$name]), ARRAY_FILTER_USE_BOTH);
    }

    private static function pendingSteps(array $state): array
    {
        $done = array_flip($state['steps'] ?? []);
        return array_filter(self::STEPS, static fn($m, $name) => !isset($done[$name]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Step 'shots_unguessable': screenshots used to be stored as uploads/shots/run_<id>_<viewport>.<ext>, which
     * anyone could enumerate (hold-out outputs included). Give every referenced legacy file an unguessable name
     * (run_<id>_<viewport>_<32 hex>.<ext>, as VerifierApi::saveShot now writes) and delete unreferenced ones.
     * Throws when a file cannot be renamed (folder permissions) so the step is retried by the next upgrade.
     */
    private static function renameGuessableShots(PDO $pdo): array
    {
        $dir = WB_UPLOADS . '/shots';
        $out = ['renamed' => 0, 'removed' => 0];
        $failed = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (!preg_match(self::LEGACY_SHOT_RE, $name, $m)) {
                continue;
            }
            $col = $m[2] === 'desktop' ? 'screenshot' : 'screenshot_mobile';
            $old = 'shots/' . $name;
            $st = $pdo->prepare('SELECT COUNT(*) FROM runs WHERE id = ? AND ' . $col . ' = ?');
            $st->execute([(int) $m[1], $old]);
            if ((int) $st->fetchColumn() === 0) {
                if (@unlink($dir . '/' . $name)) {
                    $out['removed']++;
                } else {
                    $failed[] = $name;
                }
                continue;
            }
            $new = 'run_' . $m[1] . '_' . $m[2] . '_' . random_token(16) . '.' . $m[3];
            if (!@rename($dir . '/' . $name, $dir . '/' . $new)) {
                $failed[] = $name;
                continue;
            }
            $pdo->prepare('UPDATE runs SET ' . $col . ' = ? WHERE id = ? AND ' . $col . ' = ?')->execute(['shots/' . $new, (int) $m[1], $old]);
            $out['renamed']++;
        }
        if ($failed) {
            throw new RuntimeException('uploads/shots: could not rename or delete ' . count($failed) . ' screenshot file(s) such as '
                . $failed[0] . ' — make the folder writable and run the upgrade again.');
        }
        return $out;
    }

    private static function fileHashes(): array
    {
        $out = [];
        foreach (self::schemaFiles() as $f) {
            $out[self::relative($f)] = (string) sha1_file($f);
        }
        return $out;
    }

    private static function state(PDO $pdo): array
    {
        try {
            $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
            $st->execute(['schema_version']);
            $v = $st->fetchColumn();
        } catch (PDOException $e) {
            return ['migrations' => []]; // settings table does not exist yet
        }
        $data = is_string($v) ? json_decode($v, true) : null;
        if (!is_array($data)) {
            return ['migrations' => []];
        }
        $data['migrations'] = array_values(array_filter((array) ($data['migrations'] ?? []), 'is_string'));
        $data['steps'] = array_values(array_filter((array) ($data['steps'] ?? []), 'is_string'));
        return $data;
    }

    private static function saveState(PDO $pdo, array $state): void
    {
        $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute(['schema_version', to_json($state)]);
    }

    private static function relative(string $file): string
    {
        return ltrim(str_replace('\\', '/', substr($file, strlen(WB_ROOT))), '/');
    }

    /** true = acquired, false = held by another process, null = locking unavailable. */
    private static function lock(PDO $pdo): ?bool
    {
        try {
            $v = $pdo->query("SELECT GET_LOCK('wb_migrate', 10)")->fetchColumn();
            return $v === null ? null : (int) $v === 1;
        } catch (PDOException $e) {
            return null;
        }
    }

    private static function unlock(PDO $pdo): void
    {
        try {
            $pdo->query("SELECT RELEASE_LOCK('wb_migrate')")->fetchColumn();
        } catch (PDOException $e) {
            // lock is released with the connection anyway
        }
    }
}
