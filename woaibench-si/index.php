<?php
/**
 * WOAIBench.si — front controller. Every request that is not a real file is routed here (.htaccess).
 * Local dev:  php -S localhost:8080 index.php
 */
declare(strict_types=1);

// PHP built-in server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($f !== __DIR__ . '/' && is_file($f) && !preg_match('#/(app|config|storage|sql|lang|tools|cron|docs)/#', $f)) {
        if (str_ends_with($f, '.php')) {
            // Allow only the installer to run directly.
            if (str_contains($f, '/install/')) {
                chdir(dirname($f));
                require $f;
                return true;
            }
        } else {
            return false;
        }
    }
    if (preg_match('#^/install/?$#', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '')) {
        chdir(__DIR__ . '/install');
        $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        require __DIR__ . '/install/index.php';
        return true;
    }
}

// Missing static assets: answer with a cheap plain 404 instead of booting the app.
$__p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#/(assets|uploads)/#', $__p)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

define('WB', true);
require __DIR__ . '/app/bootstrap.php';

Router::loadRoutes();
Router::dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', current_path());
