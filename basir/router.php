<?php
/**
 * موجّه خادم PHP المدمج:  php -S 0.0.0.0:7777 -t public router.php
 * يضبط أنواع الملفات الخاصة بتطبيق الويب التقدمي (PWA) ويمنع الوصول لغير مجلد public.
 */
declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$public = __DIR__ . '/public';

header('Permissions-Policy: camera=(self), microphone=(self)');
header('Referrer-Policy: no-referrer');

if ($path === '/sw.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache');
    readfile($public . '/sw.js');
    return true;
}

if ($path === '/manifest.webmanifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($public . '/manifest.webmanifest');
    return true;
}

if (str_contains($path, '..') || preg_match('#/\.#', $path)) {
    http_response_code(404);
    return true;
}

if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($public . '/index.html');
    return true;
}

return false; // الملفات الثابتة و api.php يخدمها الخادم المدمج كالمعتاد
