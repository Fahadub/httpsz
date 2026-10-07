<?php
/**
 * يطبع خيارات «-d» لتفعيل إضافات PHP اللازمة لبصير إن لم تكن مفعّلة — مفيد خصوصاً مع
 * PHP لويندوز المنزّل كملف zip بدون php.ini (حيث لا تُحمَّل mbstring و curl و ffi تلقائياً).
 * يستخدمه start.bat:  for /f "delims=" %%a in ('php tools\php_args.php') do set "PHPARGS=%%a"
 */
$need = ['mbstring', 'curl', 'openssl', 'ffi', 'gd', 'fileinfo'];
$missing = array_values(array_filter($need, static fn ($e) => !extension_loaded($e)));
$args = ['-d ffi.enable=1'];
if ($missing) {
    $win = PHP_OS_FAMILY === 'Windows';
    $file = static fn (string $dir, string $ext) => $dir . DIRECTORY_SEPARATOR . ($win ? "php_$ext.dll" : "$ext.so");
    $dirs = array_filter([(string) ini_get('extension_dir'), dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext']);
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
            $args[] = '-d extension_dir="' . $dir . '"';
        }
        foreach ($missing as $e) {
            if (is_file($file($dir, $e))) {
                $args[] = "-d extension=$e";
            }
        }
    }
}
echo implode(' ', $args);
