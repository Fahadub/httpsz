<?php

declare(strict_types=1);

namespace Udeyou\Core;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function apiError(string $code, string $message, int $status, array $extra = []): never
    {
        self::json(['success' => false, 'error' => ['code' => $code, 'message' => $message] + $extra], $status);
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 302);
        exit;
    }
}
