<?php
/**
 * WOAIBench.si — web installer.  Open https://your-domain/install/ once after uploading the files.
 * Locks itself (install/install.lock) after a successful install; delete this folder afterwards.
 *
 * The version guard below deliberately uses PHP 5-compatible syntax so that an outdated PHP version
 * gets a readable explanation instead of a blank 500 page.
 */

if (PHP_VERSION_ID < 80100) {
    header('Content-Type: text/html; charset=utf-8', true, 500);
    $v = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>WOAIBench.si</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#07080C;color:#F4EFE6;font:16px/1.7 system-ui,sans-serif">'
        . '<main style="max-width:560px;padding:32px;border:1px solid rgba(217,178,106,.3);border-radius:18px">'
        . '<h1 style="color:#D9B26A;font-weight:600">يتطلب WOAIBench.si إصدار PHP 8.1 أو أحدث</h1>'
        . '<p>الإصدار الحالي: ' . $v . '. اختر PHP 8.2 أو 8.3 من hPanel ← Advanced ← PHP Configuration ثم أعد تحميل الصفحة.</p>'
        . '<hr style="border:0;border-top:1px solid rgba(255,255,255,.12)"><div dir="ltr" style="text-align:left">'
        . '<h2 style="color:#D9B26A;font-weight:600">WOAIBench.si requires PHP 8.1 or newer</h2>'
        . '<p>Current version: ' . $v . '. Select PHP 8.2 or 8.3 in hPanel → Advanced → PHP Configuration, then reload this page.</p></div>'
        . '</main></body></html>';
    exit;
}

define('WB', true);
define('WB_INSTALLER', true);
require dirname(__DIR__) . '/app/bootstrap.php';

WB\Setup\Wizard::handle();
