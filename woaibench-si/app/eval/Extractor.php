<?php
/**
 * Pulls the gradable artefact out of a raw model reply: an HTML document, a JavaScript solution,
 * the final <answer>, or the trimmed text. Tolerates prose around the code, several fenced blocks,
 * <think> sections and fences left open when the output was cut off by max_tokens.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class Extractor
{
    private const JS_LANGS = ['javascript', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'typescript', 'tsx', 'node', 'ecmascript'];

    /** Extract code/answer for an output type; null when nothing usable is present. */
    public static function extract(string $raw, string $outputType): ?string
    {
        $raw = self::clean($raw);
        return match ($outputType) {
            'html' => self::html($raw),
            'js' => self::js($raw),
            'answer' => self::answer($raw),
            default => ($t = trim($raw)) === '' ? null : $t,
        };
    }

    /**
     * Fenced code blocks in order: list of ['lang' => string, 'body' => string, 'closed' => bool].
     * A fence opens with ``` or ~~~ (3+ chars, ≤ 3 spaces indent) and closes with the same char, at
     * least as long, without an info string. An unclosed final fence runs to the end of the text.
     */
    public static function blocks(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $blocks = [];
        $open = null;
        foreach ($lines as $line) {
            if ($open === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*([^`\s]*)[^`]*$/', $line, $m)) {
                    $open = ['char' => $m[1][0], 'len' => strlen($m[1]), 'lang' => strtolower(trim($m[2], '{}.')), 'lines' => []];
                }
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*$/', $line, $m) && $m[1][0] === $open['char'] && strlen($m[1]) >= $open['len']) {
                $blocks[] = ['lang' => $open['lang'], 'body' => implode("\n", $open['lines']), 'closed' => true];
                $open = null;
                continue;
            }
            $open['lines'][] = $line;
        }
        if ($open !== null) {
            $blocks[] = ['lang' => $open['lang'], 'body' => rtrim(implode("\n", $open['lines'])), 'closed' => false];
        }
        return $blocks;
    }

    /* ------------------------------------------------------------------ types */

    private static function html(string $raw): ?string
    {
        $blocks = self::blocks($raw);
        $tagged = array_filter($blocks, static fn($b) => in_array($b['lang'], ['html', 'htm', 'xhtml'], true) && trim($b['body']) !== '');
        $best = self::largest($tagged);
        if ($best === null) {
            $looks = array_filter($blocks, static fn($b) => self::looksLikeHtml($b['body']));
            $best = self::largest($looks);
        }
        if ($best !== null) {
            return self::stripBom(trim(self::extendToHtmlEnd($raw, $best)));
        }
        $t = ltrim($raw);
        if (preg_match('/^(<!doctype\s+html|<html[\s>])/i', $t)) {
            return rtrim($t);
        }
        return null;
    }

    private static function js(string $raw): ?string
    {
        $blocks = array_filter(self::blocks($raw), static fn($b) => trim($b['body']) !== '');
        $best = self::largest(array_filter($blocks, static fn($b) => in_array($b['lang'], self::JS_LANGS, true)));
        $best ??= self::largest(array_filter($blocks, static fn($b) => !in_array($b['lang'], ['json', 'text', 'txt', 'bash', 'sh', 'shell', 'console', 'output', 'html', 'css', 'markdown', 'md'], true)));
        $best ??= self::largest($blocks);
        if ($best !== null) {
            return self::stripBom(trim($best));
        }
        // No fences at all: accept a reply that is plainly bare code (starts like a JS program).
        $t = trim($raw);
        if ($t !== '' && !str_contains($t, '```') && preg_match('/^(?:"use strict"|\'use strict\'|\/\/|\/\*|function[\s*]|async\s+function|class\s|const\s|let\s|var\s)/', $t)) {
            return $t;
        }
        return null;
    }

    /** Inner text of the LAST <answer>…</answer>; fallbacks: unterminated tag, "Final answer:" line, short bare reply. */
    private static function answer(string $raw): ?string
    {
        if (preg_match_all('/<answer\b[^>]*>(.*?)<\/answer\s*>/is', $raw, $m) && $m[1]) {
            $last = trim((string) end($m[1]));
            return $last === '' ? null : self::unwrapAnswer($last);
        }
        if (preg_match('/<answer\b[^>]*>((?:(?!<answer\b).)*)$/is', $raw, $m) && trim($m[1]) !== '' && mb_strlen(trim($m[1])) <= 2000) {
            return self::unwrapAnswer(trim($m[1]));
        }
        $pattern = '/^[\s>*_#-]*(?:\*\*)?(?:final\s+answer|answer|الإجابة\s+النهائية|الجواب\s+النهائي|الإجابة)(?:\*\*)?\s*[:：]\s*(?:\*\*)?(.+?)\s*$/imu';
        if (preg_match_all($pattern, $raw, $m) && $m[1]) {
            $last = trim((string) end($m[1]), " \t*_`");
            if ($last !== '') {
                return $last;
            }
        }
        $t = trim($raw);
        if ($t !== '' && mb_strlen($t) <= 100 && !preg_match('/\R/', $t)) {
            return $t;
        }
        return null;
    }

    /* ------------------------------------------------------------------ helpers */

    /** Remove BOM, NULs and closed <think>/<thinking> sections some models inline before the reply. */
    private static function clean(string $raw): string
    {
        $raw = self::stripBom($raw);
        $raw = str_replace("\0", '', $raw);
        $out = preg_replace('/<(think|thinking|reasoning)>.*?<\/\1>/is', '', $raw);
        return is_string($out) ? $out : $raw;
    }

    private static function stripBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }

    private static function largest(array $blocks): ?string
    {
        $best = null;
        foreach ($blocks as $b) {
            if ($best === null || strlen($b['body']) > strlen($best)) {
                $best = $b['body'];
            }
        }
        return $best;
    }

    /**
     * A line starting with ``` inside the document (e.g. a Markdown sample in a <pre>) closes the
     * fence too early. If the block opened a document but lacks </html> while the reply has one
     * later, extend the block to that closing tag.
     */
    private static function extendToHtmlEnd(string $raw, string $block): string
    {
        if (stripos($block, '</html>') !== false || !preg_match('/^\s*(<!doctype\s+html|<html[\s>])/i', $block)) {
            return $block;
        }
        $start = strpos($raw, $block);
        $end = strripos($raw, '</html>');
        if ($start === false || $end === false || $end < $start + strlen($block)) {
            return $block;
        }
        return substr($raw, $start, $end + 7 - $start);
    }

    private static function looksLikeHtml(string $s): bool
    {
        return (bool) preg_match('/<!doctype\s+html|<html[\s>]|<body[\s>]|<head[\s>]/i', $s);
    }

    /** An answer wrapped in a code fence or backticks/quotes is unwrapped. */
    private static function unwrapAnswer(string $s): string
    {
        if (preg_match('/^```[\w-]*\s*\n?(.*?)\n?```$/s', $s, $m)) {
            $s = trim($m[1]);
        }
        if (strlen($s) >= 2 && $s[0] === '`' && substr($s, -1) === '`') {
            $s = trim($s, '`');
        }
        return trim($s);
    }
}
