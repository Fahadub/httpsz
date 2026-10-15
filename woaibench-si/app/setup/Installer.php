<?php
/**
 * Installer logic (no HTML): requirement checks, database / API-key tests and the install itself.
 * The web wizard (WB\Setup\Wizard) is a thin UI around these methods.
 */
declare(strict_types=1);

namespace WB\Setup;

defined('WB') || exit;

use Config;
use DB;
use Http;
use PDO;
use PDOException;
use RuntimeException;
use Settings;

final class Installer
{
    public const MIN_PHP = '8.1.0';
    public const DEFAULT_OR_BASE = 'https://openrouter.ai/api/v1';
    // openssl / fileinfo are not called directly (HTTPS goes through libcurl, images are checked by magic bytes);
    // openssl stays recommended because cURL's TLS and random_bytes() commonly rely on it.
    private const REQUIRED_EXT = ['pdo_mysql', 'curl', 'json', 'mbstring'];
    private const RECOMMENDED_EXT = ['gd', 'intl', 'openssl'];
    private const DIRS = ['config', 'storage', 'storage/logs', 'storage/cache', 'storage/sessions', 'uploads', 'uploads/shots', 'uploads/brand'];

    public static function lockFile(): string
    {
        return WB_ROOT . '/install/install.lock';
    }

    public static function isLocked(): bool
    {
        return is_file(self::lockFile());
    }

    /**
     * One-time setup token (storage/cache/setup-token.txt). Only someone with access to the hosting file manager
     * can read it (storage/ is never served), so a stranger who finds a fresh /install/ cannot take the site over.
     */
    public static function setupTokenFile(): string
    {
        return WB_STORAGE . '/cache/setup-token.txt';
    }

    /** Create the token file when missing. Returns false when it neither exists nor can be written. */
    public static function ensureSetupToken(): bool
    {
        $file = self::setupTokenFile();
        if (is_file($file) && self::setupToken() !== '') {
            return true;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $ok = @file_put_contents($file, \random_token(16) . "\n", LOCK_EX) !== false;
        if ($ok) {
            @chmod($file, 0640);
        }
        return $ok;
    }

    /** The current setup token ('' when there is none). */
    public static function setupToken(): string
    {
        $file = self::setupTokenFile();
        $raw = is_file($file) ? @file_get_contents($file) : false;
        return is_string($raw) && preg_match('/^\s*([a-f0-9]{32})\s*$/', $raw, $m) ? $m[1] : '';
    }

    /** Constant-time comparison of a pasted token with the stored one. */
    public static function setupTokenValid(string $given): bool
    {
        $token = self::setupToken();
        return $token !== '' && hash_equals($token, strtolower(trim($given)));
    }

    public static function removeSetupToken(): void
    {
        if (is_file(self::setupTokenFile())) {
            @unlink(self::setupTokenFile());
        }
    }

    /** 'none' (no config file) | 'working' (config + DB reachable) | 'broken' (config, DB unreachable). */
    public static function configState(): string
    {
        if (!is_file(WB_CONFIG_FILE)) {
            return 'none';
        }
        try {
            DB::pdo()->query('SELECT 1');
            return 'working';
        } catch (\Throwable $e) {
            return 'broken';
        }
    }

    /**
     * Server checks. Each: {id, label, params, detail, status ok|fail|warn|info, level required|recommended|info, hint}.
     * Missing storage/upload sub-folders are created on the way.
     */
    public static function requirements(): array
    {
        $out = [];
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $out[] = self::check('php', 'setup.req_php', [], PHP_VERSION, $phpOk ? 'ok' : 'fail', 'required', 'setup.req_php_hint');
        foreach (self::REQUIRED_EXT as $ext) {
            $ok = extension_loaded($ext);
            $out[] = self::check('ext_' . $ext, 'setup.req_ext', ['name' => $ext], $ok ? 'setup.enabled' : 'setup.missing', $ok ? 'ok' : 'fail', 'required', 'setup.req_ext_hint');
        }
        foreach (self::RECOMMENDED_EXT as $ext) {
            $ok = extension_loaded($ext);
            $out[] = self::check('ext_' . $ext, 'setup.req_ext', ['name' => $ext], $ok ? 'setup.enabled' : 'setup.missing', $ok ? 'ok' : 'warn', 'recommended', 'setup.req_ext_' . $ext);
        }
        foreach (self::DIRS as $dir) {
            $ok = self::ensureWritable(WB_ROOT . '/' . $dir);
            $out[] = self::check('dir_' . $dir, 'setup.req_writable', ['dir' => $dir . '/'], $ok ? 'setup.writable' : 'setup.not_writable', $ok ? 'ok' : 'fail', 'required', 'setup.req_writable_hint');
        }
        $ok = is_writable(WB_ROOT . '/install');
        $out[] = self::check('dir_install', 'setup.req_install_dir', ['dir' => 'install/'], $ok ? 'setup.writable' : 'setup.not_writable', $ok ? 'ok' : 'warn', 'recommended', 'setup.req_writable_hint');
        $ok = is_file(WB_ROOT . '/.htaccess');
        $out[] = self::check('htaccess', 'setup.req_htaccess', [], $ok ? 'setup.present' : 'setup.missing', $ok ? 'ok' : 'fail', 'required', 'setup.req_htaccess_hint');
        $ok = Http::isHttps();
        $out[] = self::check('https', 'setup.req_https', [], $ok ? 'setup.active' : 'setup.inactive', $ok ? 'ok' : 'warn', 'recommended', 'setup.req_https_hint');
        $out[] = self::rewriteCheck();
        $out[] = self::memoryCheck();
        $out[] = self::timeCheck();
        $server = preg_replace('/[^A-Za-z0-9 .\/\-()_]/', '', (string) ($_SERVER['SERVER_SOFTWARE'] ?? '')) ?: 'setup.unknown';
        $out[] = self::check('server', 'setup.req_server', [], mb_substr($server, 0, 60), 'info', 'info', null);
        return $out;
    }

    /** True when every required check passes. */
    public static function requirementsPass(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['level'] === 'required' && $c['status'] === 'fail') {
                return false;
            }
        }
        return true;
    }

    /**
     * Try a database connection. Returns {ok, error (lang key), params, detail (server message),
     * server, existing_tables, admins, pdo}. Never includes the password.
     */
    public static function testDb(array $db): array
    {
        $res = ['ok' => false, 'error' => null, 'params' => [], 'detail' => null, 'server' => null, 'existing_tables' => 0, 'admins' => 0, 'pdo' => null];
        if (!extension_loaded('pdo_mysql')) {
            return ['error' => 'setup.err_db_pdo'] + $res;
        }
        try {
            $pdo = DB::connect($db['host'], (int) $db['port'], $db['name'], $db['user'], $db['pass']);
        } catch (PDOException $e) {
            log_error('Installer DB test failed', ['host' => $db['host'], 'db' => $db['name'], 'user' => $db['user'], 'error' => $e->getMessage()]);
            [$key, $params] = self::dbErrorKey($e, $db);
            return ['error' => $key, 'params' => $params, 'detail' => self::scrub($e->getMessage(), $db['pass'])] + $res;
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $num = preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) ? $m[1] : '0.0.0';
        $res['server'] = ($isMaria ? 'MariaDB ' : 'MySQL ') . $num;
        if (version_compare($num, $isMaria ? '10.3.0' : '5.7.0', '<')) {
            return ['error' => 'setup.err_db_version', 'params' => ['server' => $res['server']]] + $res;
        }
        if (!$pdo->query("SHOW CHARACTER SET LIKE 'utf8mb4'")->fetch()) {
            return ['error' => 'setup.err_db_utf8mb4'] + $res;
        }
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS wb_install_probe (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $pdo->exec('DROP TABLE IF EXISTS wb_install_probe');
        } catch (PDOException $e) {
            return ['error' => 'setup.err_db_create', 'detail' => self::scrub($e->getMessage(), $db['pass'])] + $res;
        }
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $known = ['settings', 'admins', 'models', 'tracks', 'tasks', 'runs', 'campaigns', 'judgments', 'model_scores'];
        $res['existing_tables'] = count(array_intersect($known, $tables));
        if (in_array('admins', $tables, true)) {
            $res['admins'] = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        }
        $res['ok'] = true;
        $res['pdo'] = $pdo;
        return $res;
    }

    /**
     * Check an OpenRouter key from the server: GET {base}/key (validates the key), falling back to
     * GET {base}/models (reachability) when /key is not available. Short timeout; never throws.
     * Returns {ok, state: valid|reachable|invalid|unreachable|http, message key, params}.
     */
    public static function testOpenRouter(#[\SensitiveParameter] string $key): array
    {
        $base = rtrim((string) (Config::get('openrouter_base') ?: self::DEFAULT_OR_BASE), '/');
        $hdr = ['Authorization' => 'Bearer ' . $key, 'X-Title' => 'WOAIBench.si installer'];
        $r = Http::request('GET', $base . '/key', null, $hdr, 8);
        if ($r['status'] === 200 && is_array($r['json']['data'] ?? null)) {
            $d = $r['json']['data'];
            if (isset($d['usage'], $d['limit']) && is_numeric($d['limit'])) {
                return ['ok' => true, 'state' => 'valid', 'message' => 'setup.api_ok_limit', 'params' => ['usage' => fmt_money($d['usage']), 'limit' => fmt_money($d['limit'])]];
            }
            return ['ok' => true, 'state' => 'valid', 'message' => 'setup.api_ok', 'params' => []];
        }
        if ($r['status'] === 401) {
            return ['ok' => false, 'state' => 'invalid', 'message' => 'setup.api_invalid', 'params' => ['status' => $r['status']]];
        }
        if ($r['status'] === 404) {
            $r = Http::request('GET', $base . '/models', null, $hdr, 8);
            if ($r['status'] === 200 && is_array($r['json']['data'] ?? null)) {
                return ['ok' => true, 'state' => 'reachable', 'message' => 'setup.api_reachable', 'params' => ['n' => count($r['json']['data'])]];
            }
        }
        if ($r['status'] === 0) {
            $err = mb_substr(preg_replace('/\s+/', ' ', (string) $r['error']) ?? '', 0, 140);
            return ['ok' => false, 'state' => 'unreachable', 'message' => 'setup.api_unreachable', 'params' => ['error' => $err ?: 'timeout']];
        }
        return ['ok' => false, 'state' => 'http', 'message' => 'setup.api_http', 'params' => ['status' => $r['status']]];
    }

    /**
     * Run the installation. $in = {db:{host,port,name,user,pass}, site:{url,name,lang},
     * admin:{name,email,hash}, openrouter_key}. Idempotent on the database side; the config file and
     * lock are written last. Returns a summary (no secrets).
     */
    public static function install(array $in): array
    {
        \wb_time_limit(300);
        $db = $in['db'];
        $pdo = DB::connect($db['host'], (int) $db['port'], $db['name'], $db['user'], $db['pass']);
        DB::setPdo($pdo);
        Settings::flush();

        $migrate = Migrator::run($pdo);
        if (!$migrate['ok']) {
            throw new RuntimeException('Database schema: ' . $migrate['error']);
        }
        self::initialSettings($in['site']);
        $seed = Seeder::run(false);
        $changelog = self::openSeason($seed);
        $adminCreated = self::upsertAdmin($in['admin']);

        if (!Config::write(self::configArray($in))) {
            throw new RuntimeException('config_write');
        }
        $lock = @file_put_contents(self::lockFile(), to_json([
            'installed_at' => gmdate('c'), 'version' => WB_VERSION,
            'note' => 'Delete this file AND config/config.local.php to run the installer again.',
        ]) . "\n", LOCK_EX) !== false;
        self::removeSetupToken(); // one-time: the working config now locks the installer anyway

        $tables = (int) DB::val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        return [
            'tables' => $tables,
            'migrate' => array_diff_key($migrate, ['schema_version' => 1]),
            'seed' => $seed,
            'admin_created' => $adminCreated,
            'changelog' => $changelog,
            'lock' => $lock,
            'site_url' => $in['site']['url'],
            'admin_email' => $in['admin']['email'],
            'season' => (string) Settings::get('season'),
        ];
    }

    /** Hostinger cron command for the detected install path. */
    public static function cronCommand(): string
    {
        $root = realpath(WB_ROOT) ?: WB_ROOT;
        $script = $root . '/cron/worker.php';
        if (preg_match('/[^A-Za-z0-9_\/.\-]/', $script)) {
            $script = escapeshellarg($script);
        }
        return '/usr/bin/php ' . $script . ' --seconds=55';
    }

    /** Both language versions of a setup.* string (for data stored in the DB). */
    public static function bilingual(string $key, array $params = []): array
    {
        $out = [];
        foreach (['ar', 'en'] as $lang) {
            $file = WB_ROOT . '/lang/' . $lang . '/setup.php';
            $strings = is_file($file) ? (require $file) : [];
            $s = is_array($strings) ? (string) ($strings[$key] ?? $key) : $key;
            $pairs = [];
            foreach ($params as $k => $v) {
                $pairs[':' . $k] = (string) $v;
            }
            $out[$lang] = strtr($s, $pairs);
        }
        return $out;
    }

    /* ------------------------------------------------------------------ install steps */

    private static function initialSettings(array $site): void
    {
        $host = (string) (parse_url($site['url'], PHP_URL_HOST) ?: 'woaibench.si');
        $values = [
            'site_name' => $site['name'],
            'site_domain' => preg_replace('/^www\./i', '', $host),
            'default_lang' => $site['lang'],
            'installed_at' => now(),
            'installed_version' => WB_VERSION,
        ];
        // Keep an existing season when installing over existing data.
        if (DB::val('SELECT COUNT(*) FROM settings WHERE k = ?', ['season']) == 0) {
            $values['season'] = gmdate('Y.m');
        }
        Settings::setMany($values);
    }

    /** First changelog entry ("Season opened"), only when the changelog is empty. */
    private static function openSeason(array $seed): bool
    {
        if ((int) DB::val('SELECT COUNT(*) FROM changelog') > 0) {
            return false;
        }
        $season = (string) Settings::get('season');
        $tasks = (int) DB::val('SELECT COUNT(*) FROM tasks WHERE is_active = 1');
        $tracks = (int) DB::val('SELECT COUNT(*) FROM tracks WHERE is_active = 1');
        $title = self::bilingual('setup.cl_title', ['season' => $season]);
        $body = self::bilingual('setup.cl_body', ['tasks' => $tasks, 'tracks' => $tracks]);
        DB::insert('changelog', [
            'version' => $season, 'title_ar' => $title['ar'], 'title_en' => $title['en'],
            'body_ar' => $body['ar'], 'body_en' => $body['en'], 'published_at' => now(),
        ]);
        return true;
    }

    /** Create the owner account, or reset name/password of an existing admin with that email. Returns true when created. */
    private static function upsertAdmin(array $admin): bool
    {
        $email = strtolower(trim($admin['email']));
        $id = DB::val('SELECT id FROM admins WHERE email = ?', [$email]);
        if ($id) {
            DB::update('admins', ['name' => $admin['name'], 'pass_hash' => $admin['hash']], 'id = ?', [(int) $id]);
            return false;
        }
        DB::insert('admins', ['email' => $email, 'name' => $admin['name'], 'pass_hash' => $admin['hash'], 'role' => 'owner']);
        return true;
    }

    private static function configArray(array $in): array
    {
        $db = $in['db'];
        return [
            'db' => ['host' => $db['host'], 'port' => (int) $db['port'], 'name' => $db['name'], 'user' => $db['user'], 'pass' => $db['pass']],
            'openrouter_key' => (string) ($in['openrouter_key'] ?? ''),
            'openrouter_base' => (string) (Config::get('openrouter_base') ?: self::DEFAULT_OR_BASE),
            'app_secret' => bin2hex(random_bytes(32)),
            'site_url' => rtrim($in['site']['url'], '/'),
            'base_path' => base_path(),
            'debug' => false,
            'trust_proxy' => false,
            'cron_key' => bin2hex(random_bytes(24)),
            'csp_extra_script' => '',
        ];
    }

    /* ------------------------------------------------------------------ helpers */

    private static function check(string $id, string $label, array $params, string $detail, string $status, string $level, ?string $hint): array
    {
        return compact('id', 'label', 'params', 'detail', 'status', 'level', 'hint');
    }

    private static function ensureWritable(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    private static function rewriteCheck(): array
    {
        if (function_exists('apache_get_modules')) {
            $ok = in_array('mod_rewrite', apache_get_modules(), true);
            return self::check('rewrite', 'setup.req_rewrite', [], $ok ? 'setup.enabled' : 'setup.missing', $ok ? 'ok' : 'warn', 'recommended', 'setup.req_rewrite_hint');
        }
        // LiteSpeed / FPM cannot tell from PHP: the page verifies it from the browser (see wizard JS).
        return self::check('rewrite', 'setup.req_rewrite', [], 'setup.req_rewrite_js', 'info', 'recommended', 'setup.req_rewrite_hint');
    }

    private static function memoryCheck(): array
    {
        $raw = (string) ini_get('memory_limit');
        $bytes = self::bytes($raw);
        $ok = $bytes < 0 || $bytes >= 256 * 1024 * 1024;
        return self::check('memory', 'setup.req_memory', [], $bytes < 0 ? 'setup.unlimited' : $raw, $ok ? 'ok' : 'warn', 'recommended', 'setup.req_memory_hint');
    }

    private static function timeCheck(): array
    {
        $t = (int) ini_get('max_execution_time');
        $ok = $t === 0 || $t >= 300;
        return self::check('time', 'setup.req_time', [], $t === 0 ? 'setup.unlimited' : $t . ' s', $ok ? 'ok' : 'warn', 'recommended', 'setup.req_time_hint');
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Map a PDO connection error to [lang key, params]. */
    private static function dbErrorKey(PDOException $e, array $db): array
    {
        $code = (int) ($e->errorInfo[1] ?? 0) ?: (int) $e->getCode();
        return match ($code) {
            1045 => ['setup.err_db_access', []],
            1044 => ['setup.err_db_dbaccess', ['name' => $db['name']]],
            1049 => ['setup.err_db_unknown', ['name' => $db['name']]],
            2002, 2003, 2005, 2006 => ['setup.err_db_host', ['host' => $db['host']]],
            default => ['setup.err_db_generic', ['code' => $code ?: '?']],
        };
    }

    /** Remove the password (if a driver ever echoes it) and trim a server message for display. */
    private static function scrub(string $msg, string $pass): string
    {
        if ($pass !== '') {
            $msg = str_replace($pass, '********', $msg);
        }
        $msg = preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\])?\s*/', '', $msg) ?? $msg;
        return mb_substr($msg, 0, 240);
    }
}
