<?php
/**
 * Arabic / English localisation.
 * String tables live in lang/{ar,en}/*.php — each file returns ['dot.key' => 'text'].
 * Every module owns its own file (e.g. lang/ar/arena.php) so modules never collide.
 */
declare(strict_types=1);

defined('WB') || exit;

final class I18n
{
    public const LANGS = ['ar', 'en'];
    private static string $lang = 'ar';
    private static array $strings = [];
    private static array $fallback = [];

    public static function boot(?string $force = null): void
    {
        $lang = $force;
        if ($lang === null) {
            $q = $_GET['lang'] ?? null;
            if (is_string($q) && in_array($q, self::LANGS, true)) {
                $lang = $q;
                if (!headers_sent()) {
                    setcookie('wb_lang', $q, [
                        'expires' => time() + 31536000,
                        'path' => base_path() ?: '/',
                        'samesite' => 'Lax',
                        'secure' => Http::isHttps(),
                        'httponly' => false,
                    ]);
                }
            }
        }
        if ($lang === null) {
            $c = $_COOKIE['wb_lang'] ?? null;
            if (is_string($c) && in_array($c, self::LANGS, true)) {
                $lang = $c;
            }
        }
        if ($lang === null) {
            $al = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
            if ($al !== '') {
                // Primary browser language decides; Arabic browsers get Arabic, everyone else English.
                $lang = str_starts_with(ltrim($al), 'ar') ? 'ar' : 'en';
            }
        }
        if ($lang === null) {
            $d = (string) Settings::get('default_lang', 'ar');
            $lang = in_array($d, self::LANGS, true) ? $d : 'ar';
        }
        self::setLang($lang);
    }

    public static function setLang(string $lang): void
    {
        self::$lang = in_array($lang, self::LANGS, true) ? $lang : 'en';
        self::$strings = self::loadLang(self::$lang);
        self::$fallback = self::$lang === 'en' ? self::$strings : self::loadLang('en');
    }

    private static function loadLang(string $lang): array
    {
        $out = [];
        foreach (glob(WB_ROOT . '/lang/' . $lang . '/*.php') ?: [] as $file) {
            $arr = require $file;
            if (is_array($arr)) {
                $out = array_merge($out, $arr);
            }
        }
        return $out;
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $key, array $replace = []): string
    {
        $s = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        if ($replace) {
            $pairs = [];
            foreach ($replace as $k => $v) {
                $pairs[':' . $k] = (string) $v;
                $pairs['{' . $k . '}'] = (string) $v;
            }
            $s = strtr($s, $pairs);
        }
        return $s;
    }

    public static function has(string $key): bool
    {
        return isset(self::$strings[$key]) || isset(self::$fallback[$key]);
    }

    /** Strings for JavaScript: returns ['key' => 'text'] for every key starting with one of $prefixes. */
    public static function forJs(array $prefixes): array
    {
        $out = [];
        $all = array_merge(self::$fallback, self::$strings);
        foreach ($all as $k => $v) {
            foreach ($prefixes as $p) {
                if (str_starts_with($k, $p)) {
                    $out[$k] = $v;
                    break;
                }
            }
        }
        return $out;
    }
}

/** Current language code: 'ar' | 'en'. */
function lang(): string
{
    return I18n::lang();
}

function is_rtl(): bool
{
    return I18n::lang() === 'ar';
}

/** 'rtl' or 'ltr' for the dir attribute. */
function text_dir(): string
{
    return is_rtl() ? 'rtl' : 'ltr';
}

/** Translate a key. __('nav.leaderboard'), __('home.models_count', ['n' => 12]) — placeholders :n or {n}. */
function __(string $key, array $replace = []): string
{
    return I18n::t($key, $replace);
}

/** Escaped translation for direct output in HTML. */
function _e(string $key, array $replace = []): string
{
    return e(I18n::t($key, $replace));
}

/** Pick the bilingual DB column for the current language: L($track, 'name') -> name_ar|name_en (falls back to the other). */
function L(array $row, string $field): string
{
    $primary = $row[$field . '_' . lang()] ?? null;
    if ($primary !== null && $primary !== '') {
        return (string) $primary;
    }
    $other = $row[$field . '_' . (lang() === 'ar' ? 'en' : 'ar')] ?? ($row[$field] ?? '');
    return (string) $other;
}

/** Inline choice by language: tr('نص', 'text'). */
function tr(string $ar, string $en): string
{
    return lang() === 'ar' ? $ar : $en;
}

/** Same page in the other language. */
function lang_url(string $target): string
{
    $q = $_GET;
    $q['lang'] = $target;
    return url(current_path(), $q);
}
