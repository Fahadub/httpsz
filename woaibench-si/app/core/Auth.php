<?php
/**
 * Admin authentication (session based) + API bearer tokens (for the headless verifier).
 */
declare(strict_types=1);

defined('WB') || exit;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || WB_CLI) {
            return;
        }
        session_name('wb_sess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path() ?: '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '86400');
        $dir = WB_STORAGE . '/sessions';
        if ((is_dir($dir) || @mkdir($dir, 0770, true)) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $id = $_SESSION['admin_id'] ?? null;
        if (!$id) {
            return null;
        }
        // Bind session to UA to make stolen cookies less useful.
        if (($_SESSION['admin_ua'] ?? '') !== hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            self::logout();
            return null;
        }
        self::$user = DB::one('SELECT id, email, name, role, last_login_at FROM admins WHERE id = ?', [(int) $id]);
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Returns true on success. Caller must enforce rate limiting. */
    public static function attempt(string $email, string $password): bool
    {
        $row = DB::one('SELECT * FROM admins WHERE email = ?', [strtolower(trim($email))]);
        // Constant-ish time: always verify against some hash.
        $hash = $row['pass_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        if (!password_verify($password, $hash) || !$row) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            DB::update('admins', ['pass_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $row['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['admin_ua'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        unset($_SESSION['_csrf']);
        DB::update('admins', ['last_login_at' => now()], 'id = ?', [(int) $row['id']]);
        self::$resolved = false;
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_ua']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::$user = null;
        self::$resolved = true;
    }

    /**
     * Validate "Authorization: Bearer <token>" against api_tokens with the given scope.
     * Returns the token row or null.
     */
    public static function apiToken(string $scope = 'verifier'): ?array
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($hdr === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $hdr = $v;
                }
            }
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,128})$/', trim((string) $hdr), $m)) {
            return null;
        }
        $row = DB::one('SELECT * FROM api_tokens WHERE token_hash = ? AND scope = ?', [hash('sha256', $m[1]), $scope]);
        if ($row) {
            DB::update('api_tokens', ['last_used_at' => now()], 'id = ?', [(int) $row['id']]);
        }
        return $row;
    }
}

/** Gate for admin pages/endpoints: redirects to login (HTML) or 401 (JSON). */
function require_admin(): array
{
    $u = Auth::user();
    if ($u) {
        return $u;
    }
    if (wants_json()) {
        json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    redirect(url('/admin/login', ['next' => current_path()]));
}

function auth_user(): ?array
{
    return Auth::user();
}
