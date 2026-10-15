<?php
/**
 * Login / logout, own account (password, extra admins) and verifier API tokens.
 */
declare(strict_types=1);

namespace WB\Admin;

defined('WB') || exit;

use DB;
use Auth;

final class AccessController
{
    private const LOGIN_MAX = 5;      // attempts per (e-mail, client) per window
    private const LOGIN_MAX_IP = 20;  // attempts per client per window (several admins behind one NAT)
    private const LOGIN_WINDOW = 900;
    private const MIN_PASSWORD = 10;

    /** GET /admin/login */
    public static function loginForm(?string $error = null, int $code = 200): void
    {
        if ($error === null && Auth::check()) {
            \redirect(Admin::safeNext(\input('next')));
        }
        http_response_code($code);
        Admin::render('login', [
            'title' => \__('admin.login.title'),
            'bare' => true,
            'error' => $error,
            'next' => Admin::safeNext(\input('next')),
            'email' => is_string($_POST['email'] ?? null) ? mb_substr(trim($_POST['email']), 0, 190) : '',
        ]);
    }

    /**
     * POST /admin/login — generic error; every attempt reserves a slot BEFORE the password is checked (atomic, so
     * parallel requests cannot overshoot): 20 per 15 min per client and 5 per 15 min per (e-mail, client). The
     * account limit is per client, so failures sent from elsewhere can never lock the owner out.
     */
    public static function login(): void
    {
        \csrf_check();
        $email = strtolower(trim(Admin::str('email', '')));
        $password = as_str($_POST['password'] ?? '');
        $client = \ip_hash();
        $ipKey = 'admin_login_ip:' . $client;
        $pairKey = 'admin_login_email:' . $email . ':' . $client;
        if (!\rate_limit($ipKey, self::LOGIN_MAX_IP, self::LOGIN_WINDOW)
            || !\rate_limit($pairKey, self::LOGIN_MAX, self::LOGIN_WINDOW)) {
            self::loginForm(\__('admin.login.too_many'), 429);
            return;
        }
        if ($email === '' || $password === '' || strlen($password) > 1024 || !Auth::attempt($email, $password)) {
            usleep(random_int(150000, 350000));
            self::loginForm(\__('admin.login.failed'), 401);
            return;
        }
        \RateLimit::reset($ipKey, self::LOGIN_WINDOW);
        \RateLimit::reset($pairKey, self::LOGIN_WINDOW);
        \redirect(Admin::safeNext(\input('next')));
    }

    /** POST /admin/logout */
    public static function logout(): void
    {
        \csrf_check();
        Auth::logout();
        \flash('info', \__('admin.login.logged_out'));
        \redirect('/admin/login');
    }

    /** GET /admin/account */
    public static function account(): void
    {
        $user = \require_admin();
        Admin::render('account', [
            'title' => \__('admin.account.title'),
            'nav_active' => 'account',
            'user' => $user,
            'admins' => DB::all('SELECT id, email, name, role, last_login_at, created_at FROM admins ORDER BY id'),
        ]);
    }

    /** POST /admin/account/password */
    public static function changePassword(): void
    {
        $user = Admin::guardPost();
        $current = as_str($_POST['current'] ?? '');
        $new = as_str($_POST['new'] ?? '');
        $confirm = as_str($_POST['confirm'] ?? '');
        $hash = (string) DB::val('SELECT pass_hash FROM admins WHERE id = ?', [(int) $user['id']]);
        if (!\rate_limit('admin_pw:' . $user['id'], 10, 900)) {
            \flash('error', \__('admin.login.too_many'));
        } elseif (!password_verify($current, $hash)) {
            \flash('error', \__('admin.account.err_current'));
        } elseif (($msg = self::passwordProblem($new, $confirm)) !== null) {
            \flash('error', $msg);
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            DB::update('admins', ['pass_hash' => $hash], 'id = ?', [(int) $user['id']]);
            session_regenerate_id(true);
            Auth::refreshPasswordStamp($hash); // this session stays signed in; every other one ends
            \flash('success', \__('admin.account.pw_changed'));
        }
        \redirect('/admin/account');
    }

    /** POST /admin/account/admins — add another administrator. */
    public static function addAdmin(): void
    {
        Admin::guardPost();
        $email = strtolower(trim(Admin::str('email', '')));
        $name = trim(Admin::str('name', ''));
        $pass = as_str($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            \flash('error', \__('admin.account.err_email'));
        } elseif ($name === '' || mb_strlen($name) > 120) {
            \flash('error', \__('admin.account.err_name'));
        } elseif (($msg = self::passwordProblem($pass, $pass)) !== null) {
            \flash('error', $msg);
        } elseif (DB::val('SELECT id FROM admins WHERE email = ?', [$email])) {
            \flash('error', \__('admin.account.err_exists'));
        } else {
            DB::insert('admins', ['email' => $email, 'name' => $name, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'owner']);
            \flash('success', \__('admin.account.admin_added', ['email' => $email]));
        }
        \redirect('/admin/account');
    }

    /** POST /admin/account/admins/{id}/delete — remove another administrator (their sessions end at once). */
    public static function deleteAdmin(int $id): void
    {
        $user = Admin::guardPost();
        $email = DB::val('SELECT email FROM admins WHERE id = ?', [$id]);
        if ($id === (int) $user['id']) {
            \flash('error', \__('admin.account.err_delete_self'));
        } elseif (!is_string($email)) {
            \flash('error', \__('admin.not_found'));
        } else {
            DB::exec('DELETE FROM admins WHERE id = ?', [$id]);
            \log_error('Admin removed an administrator', ['admin' => $user['email'], 'removed' => $email]);
            \flash('success', \__('admin.account.admin_deleted', ['email' => $email]));
        }
        \redirect('/admin/account');
    }

    /** Null when acceptable, else a translated reason. */
    public static function passwordProblem(#[\SensitiveParameter] string $pass, #[\SensitiveParameter] string $confirm): ?string
    {
        if (mb_strlen($pass) < self::MIN_PASSWORD) {
            return \__('admin.account.err_short', ['n' => self::MIN_PASSWORD]);
        }
        if (strlen($pass) > 1024) {
            return \__('admin.account.err_long');
        }
        if (!hash_equals($pass, $confirm)) {
            return \__('admin.account.err_confirm');
        }
        return null;
    }

    /** GET /admin/tokens */
    public static function tokens(): void
    {
        \require_admin();
        $plain = $_SESSION['admin_new_token'] ?? null;
        unset($_SESSION['admin_new_token']);
        Admin::render('tokens', [
            'title' => \__('admin.tokens.title'),
            'nav_active' => 'tokens',
            'tokens' => Tokens::all(),
            'new_token' => is_array($plain) ? $plain : null,
        ]);
    }

    /** POST /admin/tokens */
    public static function createToken(): void
    {
        Admin::guardPost();
        $name = trim(Admin::str('name', ''));
        if ($name === '' || mb_strlen($name) > 120) {
            \flash('error', \__('admin.tokens.err_name'));
            \redirect('/admin/tokens');
        }
        $plain = Tokens::create($name, 'verifier');
        $_SESSION['admin_new_token'] = ['name' => $name, 'token' => $plain];
        \redirect('/admin/tokens');
    }

    /** POST /admin/tokens/{id}/revoke */
    public static function revokeToken(int $id): void
    {
        Admin::guardPost();
        $ok = Tokens::revoke($id);
        \flash($ok ? 'success' : 'error', $ok ? \__('admin.tokens.revoked') : \__('admin.not_found'));
        \redirect('/admin/tokens');
    }
}
