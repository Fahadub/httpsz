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
    private const LOGIN_MAX = 5;
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

    /** POST /admin/login — rate limited per IP and per e-mail, generic error. */
    public static function login(): void
    {
        \csrf_check();
        $email = strtolower(trim((string) \input('email', '')));
        $password = (string) ($_POST['password'] ?? '');
        if (!\rate_limit('admin_login_ip:' . \ip_hash(), self::LOGIN_MAX, self::LOGIN_WINDOW)
            || !\rate_limit('admin_login_email:' . $email, self::LOGIN_MAX, self::LOGIN_WINDOW)) {
            self::loginForm(\__('admin.login.too_many'), 429);
            return;
        }
        if ($email === '' || $password === '' || strlen($password) > 1024 || !Auth::attempt($email, $password)) {
            usleep(random_int(150000, 350000));
            self::loginForm(\__('admin.login.failed'), 401);
            return;
        }
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
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');
        $hash = (string) DB::val('SELECT pass_hash FROM admins WHERE id = ?', [(int) $user['id']]);
        if (!\rate_limit('admin_pw:' . $user['id'], 10, 900)) {
            \flash('error', \__('admin.login.too_many'));
        } elseif (!password_verify($current, $hash)) {
            \flash('error', \__('admin.account.err_current'));
        } elseif (($msg = self::passwordProblem($new, $confirm)) !== null) {
            \flash('error', $msg);
        } else {
            DB::update('admins', ['pass_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int) $user['id']]);
            session_regenerate_id(true);
            \flash('success', \__('admin.account.pw_changed'));
        }
        \redirect('/admin/account');
    }

    /** POST /admin/account/admins — add another administrator. */
    public static function addAdmin(): void
    {
        Admin::guardPost();
        $email = strtolower(trim((string) \input('email', '')));
        $name = trim((string) \input('name', ''));
        $pass = (string) ($_POST['password'] ?? '');
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

    /** Null when acceptable, else a translated reason. */
    public static function passwordProblem(string $pass, string $confirm): ?string
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
        $name = trim((string) \input('name', ''));
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
