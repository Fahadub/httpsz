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

    /** Paths that are always session-less (public, cacheable endpoints: API, badges, embeds, exports…). */
    public const STATELESS_RE = '#^/(api/v1(/|$)|badge/|embed/|export/|sitemap\.xml$|robots\.txt$|healthz$|site\.webmanifest$|worker/tick$)#';

    /** Paths that start a session for cookieless clients (forms with CSRF, admin, arena pair ownership and votes). */
    private const SESSION_PAGES_RE = '#^/(admin(/|$)|install(/|$)|request$|arena(/next|/vote)?$)#';

    /**
     * Whether this request needs a PHP session. Clients that already hold a session cookie keep it (except on the
     * always-stateless paths); without one only the few pages with forms / pair state start one, so crawlers,
     * cookieless visitors and bot 404s never create session files (inode quota on shared hosting). Every
     * browser POST lives under those paths; a POST anywhere else fails its CSRF check without a session anyway.
     */
    public static function wantsSession(string $path): bool
    {
        if (preg_match(self::STATELESS_RE, $path) === 1) {
            return false;
        }
        if (isset($_COOKIE['wb_sess'])) {
            return true;
        }
        // Sandboxed frames and token-authenticated verifier calls never start one (headless verifiers, iframes);
        // neither do crawlers on /arena (they get the page without a pair).
        if (preg_match('#^/(render/|arena/frame/|api/verifier/)#', $path) === 1 || ($path === '/arena' && is_crawler())) {
            return false;
        }
        return preg_match(self::SESSION_PAGES_RE, $path) === 1;
    }

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
        // Our custom save path is not covered by distro session-cleanup cron jobs: let PHP GC it.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        $dir = WB_STORAGE . '/sessions';
        if ((is_dir($dir) || @mkdir($dir, 0770, true)) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_start();
    }

    /** An admin session ends after this many seconds without any admin request. */
    private const IDLE_SECONDS = 43200;

    /**
     * The signed-in admin, or null. A session is valid only while its admins row exists (removed admins are out
     * at once), its password stamp matches (a password change ends every other session), the UA is unchanged and
     * it has not been idle for IDLE_SECONDS.
     */
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
        $uaOk = ($_SESSION['admin_ua'] ?? '') === hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $seen = (int) ($_SESSION['admin_seen'] ?? 0);
        $row = $uaOk && time() - $seen <= self::IDLE_SECONDS
            ? DB::one('SELECT id, email, name, role, last_login_at, pass_hash FROM admins WHERE id = ?', [(int) $id])
            : null;
        if (!$row || !hash_equals(self::passwordStamp((string) $row['pass_hash']), (string) ($_SESSION['admin_pw'] ?? ''))) {
            self::logout();
            return null;
        }
        unset($row['pass_hash']);
        if (time() - $seen > 60) {
            $_SESSION['admin_seen'] = time(); // refreshed at most once a minute (fewer session writes)
        }
        self::$user = $row;
        return self::$user;
    }

    /** Session stamp of the admin's current password hash (keyed, so the session file never holds the hash). */
    public static function passwordStamp(#[\SensitiveParameter] string $passHash): string
    {
        return substr(hash_hmac('sha256', 'admin-pw|' . $passHash, app_secret()), 0, 32);
    }

    /** Keep the current session valid after its own password change (every other session ends). */
    public static function refreshPasswordStamp(#[\SensitiveParameter] string $passHash): void
    {
        $_SESSION['admin_pw'] = self::passwordStamp($passHash);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Returns true on success. Caller must enforce rate limiting. */
    public static function attempt(string $email, #[\SensitiveParameter] string $password): bool
    {
        $row = DB::one('SELECT * FROM admins WHERE email = ?', [strtolower(trim($email))]);
        // Constant-ish time: always verify against some hash.
        $hash = $row['pass_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        if (!password_verify($password, $hash) || !$row) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            DB::update('admins', ['pass_hash' => $hash], 'id = ?', [(int) $row['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['admin_ua'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $_SESSION['admin_pw'] = self::passwordStamp($hash);
        $_SESSION['admin_seen'] = time();
        unset($_SESSION['_csrf']);
        DB::update('admins', ['last_login_at' => now()], 'id = ?', [(int) $row['id']]);
        self::$resolved = false;
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_ua'], $_SESSION['admin_pw'], $_SESSION['admin_seen']);
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
