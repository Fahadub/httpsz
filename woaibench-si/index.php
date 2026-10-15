<?php
/**
 * WOAIBench.si — front controller. Every request that is not a real file is routed here (.htaccess).
 * Local dev:  php -S localhost:8080 index.php
 */
declare(strict_types=1);

// PHP built-in server: let it serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    // Mirror the .htaccess deny rules (dotfiles, md/sql/log/lock/sh/mjs/ini) so local tests see production behaviour.
    if (preg_match('#(/\.[^/]*|\.(md|sql|log|lock|sh|mjs|ini))$#i', $f) && is_file($f)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden');
    }
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

$__p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Root icons (browsers and crawlers ask for them whatever the <link> says). .htaccess rewrites them to the files;
// this covers the PHP dev server and hosts without mod_rewrite.
if (preg_match('#/(favicon\.ico|apple-touch-icon(?:-precomposed)?\.png)$#', $__p, $__m)) {
    $__icon = __DIR__ . '/assets/img/' . ($__m[1] === 'favicon.ico' ? 'favicon.ico' : 'apple-touch-icon.png');
    if (is_file($__icon)) {
        header('Content-Type: ' . ($__m[1] === 'favicon.ico' ? 'image/x-icon' : 'image/png'));
        header('Cache-Control: public, max-age=604800');
        header('Content-Length: ' . filesize($__icon));
        readfile($__icon);
        exit;
    }
}
// Missing static assets and static-looking paths (bot probes, stale icons): a cheap plain 404 instead of booting
// the app. Badges (/badge/x.svg) are the only route with such an extension.
if (preg_match('#/(assets|uploads)/#', $__p)
    || (preg_match('#\.(ico|png|jpe?g|gif|webp|avif|bmp|svg|css|js|map|woff2?|ttf|otf|eot)$#i', $__p) && !str_contains($__p, '/badge/'))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

// php -S sets SCRIPT_NAME to the request path for URIs ending in an extension (/badge/x.svg) — normalise it.
if (PHP_SAPI === 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

define('WB', true);
require __DIR__ . '/app/bootstrap.php';

Router::loadRoutes();
Router::dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', current_path());
