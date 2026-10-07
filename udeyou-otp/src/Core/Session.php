<?php

declare(strict_types=1);

namespace Udeyou\Core;

/** Cookie session + CSRF + one-shot flash messages for the web dashboard. */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('udeyou_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => Config::bool('SESSION_SECURE_COOKIE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function clientId(): ?int
    {
        return isset($_SESSION['client_id']) ? (int) $_SESSION['client_id'] : null;
    }

    public static function login(int $clientId): void
    {
        session_regenerate_id(true);
        $_SESSION['client_id'] = $clientId;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(Request $request): void
    {
        $token = $request->input('_csrf');
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            http_response_code(419);
            View::render('error', ['title' => '419', 'message' => 'انتهت صلاحية الصفحة، حدّثها وحاول مرة أخرى.']);
            exit;
        }
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['flash'][$key] = $value;
            return null;
        }
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
}
