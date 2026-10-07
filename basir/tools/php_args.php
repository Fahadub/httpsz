<?php
/**
 * يطبع خيارات «-d» لتفعيل إضافات PHP اللازمة لبصير إن لم تكن مفعّلة — مفيد خصوصاً مع
 * PHP لويندوز المنزّل كملف zip بدون php.ini (حيث لا تُحمَّل mbstring و curl و ffi تلقائياً).
 * يستخدمه start.bat:  for /f "delims=" %%a in ('php tools\php_args.php') do set "PHPARGS=%%a"
 * و«--json» يطبعها مصفوفة (لتشغيل خادم الصوت من tools/voices.php).
 */
$need = ['mbstring', 'curl', 'openssl', 'ffi', 'gd', 'fileinfo'];
$missing = array_values(array_filter($need, static fn ($e) => !extension_loaded($e)));
$args = [['ffi.enable', '1']];
if ($missing) {
    $win = PHP_OS_FAMILY === 'Windows';
    $file = static fn (string $dir, string $ext) => $dir . DIRECTORY_SEPARATOR . ($win ? "php_$ext.dll" : "$ext.so");
    // أولاً مجلد ext بجانب PHP نفسه: المسار الافتراضي المضمَّن (مثل C:\php\ext) قد يكون لنسخة PHP أخرى
    $dirs = array_unique(array_filter([dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext', (string) ini_get('extension_dir')]));
    $dir = null;
    foreach ($dirs as $d) {
        foreach ($missing as $e) {
            if (is_file($file($d, $e))) {
                $dir = $d;
                break 2;
            }
        }
    }
    if ($dir !== null) {
        if ($dir !== ini_get('extension_dir')) {
            $args[] = ['extension_dir', $dir];
        }
        foreach ($missing as $e) {
            if (is_file($file($dir, $e))) {
                $args[] = ['extension', $e];
            }
        }
    }
}
if (in_array('--json', $argv ?? [], true)) {
    echo json_encode(array_merge(...array_map(static fn ($a) => ['-d', "$a[0]=$a[1]"], $args)));
} else {
    echo implode(' ', array_map(static fn ($a) => $a[0] === 'extension_dir' ? "-d $a[0]=\"$a[1]\"" : "-d $a[0]=$a[1]", $args));
}
