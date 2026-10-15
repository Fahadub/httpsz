<?php
/**
 * Tiny router. Route files in app/routes/*.php register handlers:
 *
 *   Router::get('/model/{slug}', function (string $slug) { ... });
 *   Router::post('/arena/vote', fn() => ...);
 *   Router::get('/run/{id:\d+}', fn(string $id) => ...);   // custom regex
 *   Router::any('/api/v1/{rest:.*}', ...);
 *
 * Placeholders default to [^/]+ . Handlers receive captured params as strings, in order.
 */
declare(strict_types=1);

defined('WB') || exit;

final class Router
{
    /** @var array<int, array{0:string[],1:string,2:string,3:callable}> */
    private static array $routes = [];

    public static function get(string $pattern, callable $handler): void
    {
        self::add(['GET', 'HEAD'], $pattern, $handler);
    }

    public static function post(string $pattern, callable $handler): void
    {
        self::add(['POST'], $pattern, $handler);
    }

    public static function any(string $pattern, callable $handler): void
    {
        self::add(['GET', 'HEAD', 'POST', 'OPTIONS'], $pattern, $handler);
    }

    public static function add(array $methods, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/', static function ($m) {
            $re = $m[2] ?? '[^/]+';
            return '(?P<' . $m[1] . '>' . $re . ')';
        }, rtrim($pattern, '/') ?: '/');
        self::$routes[] = [$methods, $pattern, '#^' . $regex . '$#u', $handler];
    }

    public static function loadRoutes(): void
    {
        $files = glob(WB_APP . '/routes/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            require $file;
        }
    }

    public static function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $allowed = false;
        foreach (self::$routes as [$methods, $pattern, $regex, $handler]) {
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            if (!in_array($method, $methods, true)) {
                $allowed = true;
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[] = $v;
                }
            }
            $handler(...$params);
            return;
        }
        if ($allowed) {
            abort(405, 'Method not allowed');
        }
        abort(404);
    }

    /** Registered patterns (used by the sitemap and debug tools). */
    public static function patterns(): array
    {
        return array_map(static fn($r) => $r[1], self::$routes);
    }
}
