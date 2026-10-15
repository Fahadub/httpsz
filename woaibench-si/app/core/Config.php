<?php
/**
 * Static configuration from config/config.local.php (written by the installer, never committed).
 * See config/config.sample.php for all keys.
 */
declare(strict_types=1);

defined('WB') || exit;

final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (is_file(WB_CONFIG_FILE)) {
            $cfg = require WB_CONFIG_FILE;
            if (is_array($cfg)) {
                self::$data = $cfg;
            }
        }
        // Environment overrides (useful for local dev / CI): WB_DB_HOST, WB_DB_NAME, ...
        $env = [
            'db.host' => 'WB_DB_HOST', 'db.port' => 'WB_DB_PORT', 'db.name' => 'WB_DB_NAME',
            'db.user' => 'WB_DB_USER', 'db.pass' => 'WB_DB_PASS',
            'openrouter_key' => 'WB_OPENROUTER_KEY', 'openrouter_base' => 'WB_OPENROUTER_BASE',
            'app_secret' => 'WB_APP_SECRET', 'debug' => 'WB_DEBUG', 'site_url' => 'WB_SITE_URL',
        ];
        foreach ($env as $key => $var) {
            $v = getenv($var);
            if ($v !== false && $v !== '') {
                self::set($key, $var === 'WB_DEBUG' ? ($v === '1' || $v === 'true') : $v);
            }
        }
    }

    /** Dot-notation getter: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return $default;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    public static function set(string $key, mixed $value): void
    {
        $node = &self::$data;
        foreach (explode('.', $key) as $seg) {
            if (!isset($node[$seg]) || !is_array($node[$seg])) {
                $node[$seg] = $node[$seg] ?? [];
            }
            $node = &$node[$seg];
        }
        $node = $value;
    }

    public static function all(): array
    {
        return self::$data;
    }

    public static function installed(): bool
    {
        return is_file(WB_CONFIG_FILE) && self::get('db.name') !== null;
    }

    /**
     * Persist config to config/config.local.php (installer + admin settings).
     * Uses var_export so values can never break out of the PHP array literal.
     */
    public static function write(array $data): bool
    {
        $php = "<?php\n// WOAIBench.si local configuration — generated " . gmdate('c') . "\n"
            . "// Contains secrets. Never commit this file. Protected by config/.htaccess.\n"
            . "defined('WB') || exit;\n\nreturn " . var_export($data, true) . ";\n";
        $dir = dirname(WB_CONFIG_FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return false;
        }
        $tmp = WB_CONFIG_FILE . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, WB_CONFIG_FILE)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(WB_CONFIG_FILE, true);
        }
        self::$data = $data;
        return true;
    }
}
