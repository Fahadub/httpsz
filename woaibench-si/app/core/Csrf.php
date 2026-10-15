<?php
/**
 * CSRF protection. Every state-changing request (POST) from a browser must carry the token:
 *   forms: <?= csrf_field() ?>
 *   fetch: header 'X-CSRF-Token' (read from <meta name="csrf-token">; WB.fetchJSON adds it automatically)
 */
declare(strict_types=1);

defined('WB') || exit;

final class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = random_token(32);
        }
        return $_SESSION['_csrf'];
    }

    public static function valid(?string $token = null): bool
    {
        $token ??= $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? json_body()['_csrf'] ?? null;
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    /** Abort with 419 when the token is missing/invalid. */
    public static function check(): void
    {
        if (!self::valid()) {
            abort(419, 'Session expired. Please reload the page and try again.');
        }
    }
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function csrf_check(): void
{
    Csrf::check();
}
