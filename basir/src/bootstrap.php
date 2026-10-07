<?php
/**
 * بصير — أدوات مشتركة: المسارات، قراءة/كتابة ملفات JSON (بدون قاعدة بيانات)، والإعدادات.
 */
declare(strict_types=1);

const BASIR_VERSION = '1.0.0';

define('BASIR_ROOT', dirname(__DIR__));
define('BASIR_DATA', rtrim(getenv('BASIR_DATA_DIR') ?: BASIR_ROOT . '/data', '/\\'));

require_once __DIR__ . '/Providers.php';
require_once __DIR__ . '/Prompts.php';

/** الإعدادات الافتراضية المشتركة بين كل الأجهزة (الكفيف لا يحتاج لضبطها). */
const BASIR_DEFAULT_SETTINGS = [
    'language'    => 'ar',
    'speech_lang' => 'ar-SA',
    'speech_lang_en' => 'en-US',
    'speech_rate' => 1.0,
    'step_m'      => 0.7,
    'interval_s'  => 2.0,
    'image_px'    => 768,
    'voice_ar'    => '',
    'voice_en'    => '',
];

function data_path(string $rel): string
{
    return BASIR_DATA . '/' . ltrim($rel, '/');
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('لا يمكن إنشاء المجلد: ' . $dir);
    }
}

function read_json(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return $default;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : $default;
}

/** كتابة ذرّية: ملف مؤقت ثم إعادة تسمية، حتى لا يتلف الملف إذا انقطع الطلب. */
function write_json(string $file, array $data): void
{
    write_file_atomic($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function write_file_atomic(string $file, string $content): void
{
    ensure_dir(dirname($file));
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
        @unlink($tmp); // قرص ممتلئ: لا نترك ملفاً ناقصاً
        throw new RuntimeException('تعذر حفظ الملف: ' . basename($file));
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        // ويندوز لا يسمح أحياناً بإعادة التسمية فوق ملف موجود
        @unlink($file);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('تعذر حفظ الملف: ' . basename($file));
        }
    }
}

function load_config(): array
{
    return read_json(data_path('config.json'));
}

function save_config(array $cfg): void
{
    $cfg['updated_at'] = gmdate('c');
    write_json(data_path('config.json'), $cfg);
}

function is_configured(array $cfg): bool
{
    if (empty($cfg['protocol']) || empty($cfg['base_url']) || empty($cfg['model'])) {
        return false;
    }
    $preset = Providers::preset($cfg['provider'] ?? 'custom');
    return !empty($cfg['api_key']) || !$preset['needs_key'];
}

function settings_of(array $cfg): array
{
    $out = [];
    foreach (BASIR_DEFAULT_SETTINGS as $k => $v) {
        $out[$k] = $cfg['settings'][$k] ?? $v;
    }
    return $out;
}

/** تلميح آمن للمفتاح: لا يُرسل المفتاح الكامل للمتصفح أبداً. */
function key_hint(string $key): string
{
    $len = strlen($key);
    if ($len === 0) {
        return '';
    }
    if ($len <= 8) {
        return str_repeat('•', $len);
    }
    return substr($key, 0, 3) . '…' . substr($key, -4);
}

function clamp_float($v, float $min, float $max, float $def): float
{
    if (!is_numeric($v)) {
        return $def;
    }
    return max($min, min($max, (float) $v));
}

// ───────────────────────── اللغة ─────────────────────────

const BASIR_LANGS = ['ar', 'en'];

/** لغة الطلب الحالي (ar افتراضياً). يضبطها api.php من ?lang= أو من إعداد الخادم. */
function basir_lang(?string $set = null): string
{
    static $lang = 'ar';
    if ($set !== null && in_array($set, BASIR_LANGS, true)) {
        $lang = $set;
    }
    return $lang;
}

/** نص بلغة الطلب الحالي. */
function tr(string $ar, string $en): string
{
    return basir_lang() === 'en' ? $en : $ar;
}
