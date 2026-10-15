<?php
/** Core routes: health check and web manifest. */
defined('WB') || exit;

Router::get('/healthz', static function (): void {
    $ok = true;
    try {
        DB::val('SELECT 1');
    } catch (Throwable $e) {
        $ok = false;
    }
    json_out(['ok' => $ok, 'version' => WB_VERSION, 'time' => gmdate('c')], $ok ? 200 : 503);
});

Router::get('/site.webmanifest', static function (): void {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name' => setting('site_name') . ' — ' . setting('site_domain'),
        'short_name' => setting('site_name'),
        'start_url' => url('/'),
        'display' => 'standalone',
        'background_color' => '#07080C',
        'theme_color' => '#07080C',
        'icons' => [
            ['src' => asset('img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});
