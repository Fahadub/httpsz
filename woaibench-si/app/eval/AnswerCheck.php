<?php
/**
 * Grades `answer` tasks deterministically: extracts the final <answer> and compares it with the
 * expected value (exact | number | regex | set | json | one_of). Number parsing understands
 * thousands separators, fractions, percentages, scientific notation and Arabic-Indic digits.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class AnswerCheck
{
    /** Check a raw model reply against an answer spec → ['pass','got','expected','detail']. */
    public static function check(string $raw, array $spec): array
    {
        $type = is_string($spec['type'] ?? null) ? $spec['type'] : 'exact';
        $expected = $type === 'one_of' ? ($spec['values'] ?? ($spec['value'] ?? [])) : ($spec['value'] ?? null);
        $got = Extractor::extract($raw, 'answer');
        if ($got === null || trim($got) === '') {
            return ['pass' => false, 'got' => null, 'expected' => $expected, 'detail' => 'no <answer> found in the reply'];
        }
        $got = mb_substr(trim($got), 0, 5000);
        try {
            [$pass, $detail] = match ($type) {
                'exact' => self::exact($got, $expected, !empty($spec['ci'])),
                'number' => self::number($got, $spec),
                'regex' => self::regex($got, $spec),
                'set' => self::set($got, $expected, !empty($spec['ci'])),
                'json' => self::json($got, $expected),
                'one_of' => self::oneOf($got, $expected, !empty($spec['ci'])),
                default => [false, 'unknown answer type: ' . $type],
            };
        } catch (\Throwable $e) {
            [$pass, $detail] = [false, 'answer check error: ' . $e->getMessage()];
        }
        return ['pass' => $pass, 'got' => $got, 'expected' => $expected, 'detail' => $detail];
    }

    /**
     * Parse the number an answer states ([] if none). A reply that starts with a number uses that
     * number; otherwise the last number in the text is taken. Returns candidates: percentages yield both
     * the written value and its fraction (75% → [75, 0.75]).
     */
    public static function parseNumbers(string $text): array
    {
        $t = self::normalizeDigits($text);
        $t = str_replace(['−', '–', '—', '\\,', '\\!', '$', '€', '£', '¥', '٪'], ['-', '-', '-', '', '', '', '', '', '', '%'], $t);
        $t = preg_replace('/\\\\(?:text|mathrm|mathbf)\{([^}]*)\}/', '$1', $t) ?? $t;
        $t = preg_replace('/\\\\(?:d|t)?frac\{([^}]*)\}\{([^}]*)\}/', '($1)/($2)', $t) ?? $t;
        $t = str_replace(['\\times', '×', '\\cdot', '·'], ['x', 'x', 'x', 'x'], $t);
        $num = '[-+]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?|[-+]?\.\d+';
        $pattern = '/(?<![\w.])(?:\(?(' . $num . ')\)?\s*\/\s*\(?(' . $num . ')\)?'      // fraction a/b
            . '|(' . $num . ')\s*(?:[eE]([-+]?\d+)|x\s*10\s*\^\s*\{?\s*([-+]?\d+)\s*\}?)'   // scientific
            . '|(' . $num . ')(\s*%)?)/u';
        if (!preg_match_all($pattern, $t, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        // "12 (from 3×4)" → the leading number; "x = 12" / "total: 1,234" → the last one.
        $leading = preg_match('/^[\s(\[{=:~≈]*[-+]?[\d.]/u', $t) === 1;
        $m = array_map(static fn($g) => is_array($g) ? $g[0] : '', $leading ? $all[0] : end($all));
        if (($m[1] ?? '') !== '' && ($m[2] ?? '') !== '') {
            $den = self::toFloat($m[2]);
            return $den == 0.0 ? [] : [self::toFloat($m[1]) / $den];
        }
        if (($m[3] ?? '') !== '') {
            $exp = ($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? '0');
            return [self::toFloat($m[3]) * (10 ** (int) $exp)];
        }
        $v = self::toFloat($m[6] ?? '0');
        return !empty($m[7]) ? [$v, $v / 100] : [$v];
    }

    /** Arabic-Indic / Extended Arabic-Indic digits and separators → ASCII. */
    public static function normalizeDigits(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $map = ['٫' => '.', '٬' => ',', '،' => ',', '؛' => ';'];
            for ($d = 0; $d <= 9; $d++) {
                $map[mb_chr(0x0660 + $d, 'UTF-8')] = (string) $d;
                $map[mb_chr(0x06F0 + $d, 'UTF-8')] = (string) $d;
            }
        }
        return strtr($s, $map);
    }

    /** Text normalisation used by exact / set / one_of: NFC, digits, whitespace, wrappers, trailing period. */
    public static function normalizeText(string $s, bool $ci): string
    {
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C) ?: $s;
        }
        $s = self::normalizeDigits($s);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        for ($k = 0; $k < 3; $k++) {
            $before = $s;
            $s = preg_replace('/^(\*\*|__|\$\$|\$)(.*)\1$/su', '$2', $s) ?? $s;
            $s = preg_replace('/^(["\'`«“‘])(.*)(["\'`»”’])$/su', '$2', $s) ?? $s;
            $s = trim(rtrim($s, '.。'));
            if ($s === $before) {
                break;
            }
        }
        return $ci ? mb_strtolower($s, 'UTF-8') : $s;
    }

    /* ------------------------------------------------------------------ types */

    private static function exact(string $got, mixed $expected, bool $ci): array
    {
        if (!is_scalar($expected)) {
            return [false, 'spec value must be a string'];
        }
        $pass = self::normalizeText($got, $ci) === self::normalizeText((string) $expected, $ci);
        return [$pass, $pass ? 'exact match' : 'mismatch'];
    }

    private static function number(string $got, array $spec): array
    {
        if (!is_numeric($spec['value'] ?? null)) {
            return [false, 'spec value must be numeric'];
        }
        $want = (float) $spec['value'];
        $tol = is_numeric($spec['tolerance'] ?? null) ? abs((float) $spec['tolerance']) : 1e-9;
        $rel = is_numeric($spec['rel_tolerance'] ?? null) ? abs((float) $spec['rel_tolerance']) : 0.0;
        $allowed = max($tol, $rel * abs($want), 1e-12);
        $cands = self::parseNumbers($got);
        if (!$cands) {
            return [false, 'no number found in the answer'];
        }
        foreach ($cands as $c) {
            if (is_finite($c) && abs($c - $want) <= $allowed) {
                return [true, 'parsed ' . self::fmt($c) . ' (expected ' . self::fmt($want) . ' ± ' . self::fmt($allowed) . ')'];
            }
        }
        return [false, 'parsed ' . self::fmt($cands[0]) . ' (expected ' . self::fmt($want) . ' ± ' . self::fmt($allowed) . ')'];
    }

    private static function regex(string $got, array $spec): array
    {
        $re = StaticChecks::compile(is_scalar($spec['pattern'] ?? null) ? (string) $spec['pattern'] : '', !empty($spec['ci']), is_string($spec['flags'] ?? null) ? $spec['flags'] : '');
        if ($re === null) {
            return [false, 'invalid pattern in spec'];
        }
        $n = StaticChecks::countMatches($re, $got);
        if (is_string($n)) {
            return [false, $n];
        }
        return [$n > 0, $n > 0 ? 'pattern matched' : 'pattern not matched'];
    }

    private static function set(string $got, mixed $expected, bool $ci): array
    {
        if (!is_array($expected)) {
            return [false, 'spec value must be a list'];
        }
        $have = self::items($got, $ci);
        $want = [];
        foreach ($expected as $v) {
            if (is_scalar($v)) {
                $want[self::normalizeText((string) $v, $ci)] = true;
            }
        }
        $missing = array_diff_key($want, $have);
        $extra = array_diff_key($have, $want);
        $pass = !$missing && !$extra;
        $detail = $pass ? 'same ' . count($want) . ' item(s)'
            : trim(($missing ? 'missing: ' . implode(', ', array_slice(array_keys($missing), 0, 5)) . ' ' : '')
                . ($extra ? 'unexpected: ' . implode(', ', array_slice(array_keys($extra), 0, 5)) : ''));
        return [$pass, $detail];
    }

    private static function json(string $got, mixed $expected): array
    {
        $txt = trim($got);
        if (preg_match('/^```[\w-]*\s*(.*?)\s*```$/s', $txt, $m)) {
            $txt = $m[1];
        }
        $decoded = json_decode($txt, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [false, 'answer is not valid JSON: ' . json_last_error_msg()];
        }
        $pass = self::deepEqual($decoded, $expected);
        return [$pass, $pass ? 'JSON equal' : 'JSON differs'];
    }

    private static function oneOf(string $got, mixed $values, bool $ci): array
    {
        $g = self::normalizeText($got, $ci);
        foreach ((array) $values as $v) {
            if (is_scalar($v) && self::normalizeText((string) $v, $ci) === $g) {
                return [true, 'matches an accepted answer'];
            }
        }
        return [false, 'not one of the accepted answers'];
    }

    /* ------------------------------------------------------------------ helpers */

    /** Split a list answer (commas, semicolons, newlines, Arabic comma; JSON arrays too) into normalized keys. */
    private static function items(string $got, bool $ci): array
    {
        $txt = trim($got);
        $list = null;
        if (str_starts_with($txt, '[')) {
            $d = json_decode($txt, true);
            $list = is_array($d) && array_is_list($d) ? $d : null;
            $txt = $list === null ? trim($txt, '[]') : $txt;
        }
        if ($list === null) {
            $list = preg_split('/[,;\n\r،؛]+/u', $txt) ?: [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s+/u', '', (string) $item) ?? (string) $item;
            $key = self::normalizeText($item, $ci);
            if ($key !== '') {
                $out[$key] = true;
            }
        }
        return $out;
    }

    /** Deep equality: objects order-insensitive, lists order-sensitive, 1 == 1.0, strings exact. */
    public static function deepEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
                return false;
            }
            if (array_is_list($a)) {
                foreach ($a as $i => $v) {
                    if (!self::deepEqual($v, $b[$i])) {
                        return false;
                    }
                }
                return true;
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::deepEqual($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return abs((float) $a - (float) $b) <= 1e-9 * max(1.0, abs((float) $a));
        }
        return $a === $b;
    }

    private static function toFloat(string $s): float
    {
        return (float) str_replace(',', '', $s);
    }

    private static function fmt(float $v): string
    {
        return sprintf('%.10g', $v);
    }
}
