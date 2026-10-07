<?php

declare(strict_types=1);

namespace Udeyou\Core;

final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /** Patterns support "{id}" placeholders (digits only). */
    public function dispatch(Request $request): void
    {
        $methodMismatch = false;

        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            if ($method !== $request->method) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler($request, ...array_values($params));
            return;
        }

        $isApi = str_starts_with($request->path, '/api/');
        if ($methodMismatch) {
            $isApi ? Response::apiError('method_not_allowed', 'HTTP method not allowed for this endpoint', 405)
                   : self::plain(405, 'Method Not Allowed');
        }
        $isApi ? Response::apiError('not_found', 'Endpoint not found', 404) : self::plain(404, 'الصفحة غير موجودة');
    }

    private static function plain(int $status, string $text): never
    {
        http_response_code($status);
        View::render('error', ['title' => (string) $status, 'message' => $text]);
        exit;
    }
}
