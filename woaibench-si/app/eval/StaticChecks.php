<?php
/**
 * Deterministic static checks on extracted code (ARCHITECTURE §5.1). Pure analysis: nothing is
 * ever executed. Patterns come from task JSON, so every regex is compiled defensively and run with
 * a reduced backtracking budget; a bad or runaway pattern fails the check instead of the run.
 */
declare(strict_types=1);

namespace WB\Eval;

defined('WB') || exit;

final class StaticChecks
{
    private const MAX_PARSE_BYTES = 4 * 1024 * 1024;
    private const BACKTRACK_LIMIT = '200000';

    private const LABELS = [
        'contains' => ['Contains the required pattern', 'يتضمّن النمط المطلوب'],
        'not_contains' => ['Avoids the forbidden pattern', 'يخلو من النمط المحظور'],
        'selector' => ['Required elements are present', 'العناصر المطلوبة موجودة'],
        'max_bytes' => ['Within the size limit', 'ضمن حدّ الحجم المسموح'],
        'min_bytes' => ['Substantial output', 'ناتج بحجم كافٍ'],
        'no_external' => ['No unapproved external requests', 'لا طلبات خارجية غير مسموح بها'],
        'viewport' => ['Responsive viewport meta tag', 'وسم viewport للتصميم المتجاوب'],
        'lang_attr' => ['Document language declared', 'لغة المستند مُعلنة'],
        'dir_rtl' => ['Right-to-left document direction', 'اتجاه المستند من اليمين إلى اليسار'],
        'html_parses' => ['Well-formed HTML', 'بنية HTML سليمة'],
        'single_file' => ['Single self-contained file', 'ملف واحد مكتفٍ بذاته'],
        'regex_count' => ['Pattern occurs often enough', 'تكرار النمط بالقدر الكافي'],
        'js_parses' => ['Balanced JavaScript syntax', 'بنية JavaScript متوازنة'],
    ];

    /** Elements whose href/src/etc. load a resource (navigation links like <a href> are not loads). */
    private const RESOURCE_ATTRS = ['src', 'href', 'srcset', 'poster', 'data', 'action', 'formaction', 'background', 'xlink:href'];

    private static array $domCache = [];

    /**
     * Run static checks → list of ['id','type','pass','score','weight','detail','label_en','label_ar'].
     * Empty code fails every check (an empty reply must never earn "not_contains" points).
     */
    public static function run(string $code, string $outputType, array $checks): array
    {
        self::$domCache = [];
        $code = mb_scrub($code, 'UTF-8');
        $empty = trim($code) === '';
        $out = [];
        foreach (array_values($checks) as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = is_string($c['type'] ?? null) ? $c['type'] : '';
            [$pass, $score, $detail] = $empty
                ? [false, 0.0, 'empty output']
                : self::evaluate($type, $c, $code, $outputType);
            $label = self::LABELS[$type] ?? [$type, $type];
            $out[] = [
                'id' => is_scalar($c['id'] ?? null) && (string) $c['id'] !== '' ? (string) $c['id'] : ($type !== '' ? $type : 'check') . '_' . ($i + 1),
                'type' => $type,
                'pass' => $pass,
                'score' => round(clamp((float) $score, 0.0, 1.0), 4),
                'weight' => is_numeric($c['weight'] ?? null) ? max(0.0, (float) $c['weight']) : 1.0,
                'detail' => mb_substr($detail, 0, 500),
                'label_en' => is_string($c['label_en'] ?? null) && $c['label_en'] !== '' ? $c['label_en'] : $label[0],
                'label_ar' => is_string($c['label_ar'] ?? null) && $c['label_ar'] !== '' ? $c['label_ar'] : $label[1],
            ];
        }
        self::$domCache = [];
        return $out;
    }

    /**
     * Compile a task-supplied pattern safely. Returns the delimited PCRE pattern, or null if invalid.
     * $flags may contain any of "imsx"; "u" is always on.
     */
    public static function compile(string $pattern, bool $ci = false, string $flags = ''): ?string
    {
        $delim = null;
        foreach (['~', '#', '%', '@', '!', ';', '`', '"', "'", "\x01"] as $d) {
            if (!str_contains($pattern, $d)) {
                $delim = $d;
                break;
            }
        }
        if ($delim === null || $pattern === '') {
            return null;
        }
        $mods = 'u' . ($ci ? 'i' : '') . preg_replace('/[^msx]/', '', $flags);
        $re = $delim . $pattern . $delim . implode('', array_unique(str_split($mods)));
        return @preg_match($re, '') === false ? null : $re;
    }

    /** preg_match_all with a reduced backtrack budget; int count, or an error string. */
    public static function countMatches(string $re, string $subject): int|string
    {
        $old = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', self::BACKTRACK_LIMIT);
        $n = @preg_match_all($re, $subject);
        $err = $n === false ? preg_last_error_msg() : null;
        ini_set('pcre.backtrack_limit', (string) $old);
        return $n === false ? 'regex failed: ' . $err : (int) $n;
    }

    /* ------------------------------------------------------------------ dispatch */

    /** @return array{0: bool, 1: float, 2: string} */
    private static function evaluate(string $type, array $c, string $code, string $outputType): array
    {
        try {
            return match ($type) {
                'contains' => self::contains($c, $code, false),
                'not_contains' => self::contains($c, $code, true),
                'selector' => self::selector($c, $code),
                'max_bytes' => self::bytes($c, $code, true),
                'min_bytes' => self::bytes($c, $code, false),
                'no_external' => self::noExternal($c, $code, $outputType),
                'viewport' => self::viewport($code),
                'lang_attr' => self::langAttr($c, $code),
                'dir_rtl' => self::dirRtl($code),
                'html_parses' => self::htmlParses($c, $code),
                'single_file' => self::singleFile($code, $outputType),
                'regex_count' => self::regexCount($c, $code),
                'js_parses' => self::jsParses($code, $outputType),
                default => [false, 0.0, 'unknown check type'],
            };
        } catch (\Throwable $e) {
            return [false, 0.0, 'check error: ' . $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ checks */

    private static function contains(array $c, string $code, bool $negate): array
    {
        $patterns = is_array($c['pattern'] ?? null) ? array_values($c['pattern']) : [$c['pattern'] ?? null];
        $patterns = array_values(array_filter($patterns, static fn($p) => is_scalar($p) && (string) $p !== ''));
        if (!$patterns) {
            return [false, 0.0, 'missing pattern'];
        }
        $regex = !empty($c['regex']);
        $ci = !empty($c['ci']);
        $good = 0;
        $notes = [];
        foreach ($patterns as $p) {
            $p = (string) $p;
            if ($regex) {
                $re = self::compile($p, $ci, self::str($c, 'flags'));
                if ($re === null) {
                    return [false, 0.0, 'invalid pattern: ' . mb_substr($p, 0, 80)];
                }
                $n = self::countMatches($re, $code);
                if (is_string($n)) {
                    return [false, 0.0, $n];
                }
                $found = $n > 0;
            } else {
                $found = $ci ? mb_stripos($code, $p) !== false : str_contains($code, $p);
            }
            if ($found !== $negate) {
                $good++;
            } else {
                $notes[] = mb_substr($p, 0, 60);
            }
        }
        $total = count($patterns);
        $pass = $good === $total;
        $detail = $pass
            ? ($negate ? 'forbidden pattern absent' : 'pattern found')
            : ($negate ? 'found forbidden: ' : 'not found: ') . implode(' | ', $notes);
        return [$pass, $good / $total, $detail];
    }

    private static function selector(array $c, string $code): array
    {
        $sel = trim(self::str($c, 'sel', self::str($c, 'selector')));
        if ($sel === '') {
            return [false, 0.0, 'missing selector'];
        }
        $xpath = self::cssToXpath($sel);
        if ($xpath === null) {
            return [false, 0.0, 'unsupported selector: ' . mb_substr($sel, 0, 80)];
        }
        $dom = self::dom($code);
        if ($dom === null) {
            return [false, 0.0, 'document could not be parsed'];
        }
        $nodes = @$dom['xp']->query($xpath);
        if ($nodes === false) {
            return [false, 0.0, 'unsupported selector: ' . mb_substr($sel, 0, 80)];
        }
        $n = $nodes->length;
        $min = max(0, (int) ($c['min'] ?? 1));
        $max = isset($c['max']) && is_numeric($c['max']) ? (int) $c['max'] : null;
        $pass = $n >= $min && ($max === null || $n <= $max);
        $score = $pass ? 1.0 : (($max !== null && $n > $max) ? 0.0 : ($min > 0 ? $n / $min : 0.0));
        $range = $max === null ? "≥ {$min}" : "{$min}–{$max}";
        return [$pass, $score, "{$n} match(es) for \"{$sel}\" (expected {$range})"];
    }

    private static function bytes(array $c, string $code, bool $isMax): array
    {
        if (!is_numeric($c['value'] ?? null)) {
            return [false, 0.0, 'missing value'];
        }
        $limit = (int) $c['value'];
        $n = strlen($code);
        $pass = $isMax ? $n <= $limit : $n >= $limit;
        return [$pass, $pass ? 1.0 : 0.0, number_format($n) . ' bytes (' . ($isMax ? 'max ' : 'min ') . number_format($limit) . ')'];
    }

    private static function noExternal(array $c, string $code, string $outputType): array
    {
        $allow = [];
        foreach ((array) ($c['allow'] ?? []) as $h) {
            if (is_string($h) && $h !== '') {
                $h = strtolower(trim($h));
                $h = (string) (parse_url(str_contains($h, '://') ? $h : 'http://' . $h, PHP_URL_HOST) ?? $h);
                $allow[] = ltrim($h, '*.');
            }
        }
        $bad = [];
        foreach (self::references($code, $outputType) as $ref) {
            $host = self::externalHost($ref);
            if ($host === null || self::hostAllowed($host, $allow)) {
                continue;
            }
            $bad[$host] = true;
        }
        if (!$bad) {
            return [true, 1.0, 'no external requests' . ($allow ? ' outside: ' . implode(', ', $allow) : '')];
        }
        $hosts = array_keys($bad);
        return [false, 0.0, 'external hosts: ' . implode(', ', array_slice($hosts, 0, 6)) . (count($hosts) > 6 ? ' …' : '')];
    }

    private static function viewport(string $code): array
    {
        $dom = self::dom($code);
        if ($dom === null) {
            return [false, 0.0, 'document could not be parsed'];
        }
        $metas = $dom['xp']->query("//meta[translate(@name,'VIEWPORT','viewport')='viewport']");
        if (!$metas || $metas->length === 0) {
            return [false, 0.0, 'no <meta name="viewport">'];
        }
        $content = strtolower((string) $metas->item(0)->getAttribute('content'));
        if (preg_match('/width\s*=\s*device-width/', $content)) {
            return [true, 1.0, 'viewport: ' . mb_substr($content, 0, 120)];
        }
        return [false, 0.5, 'viewport meta without width=device-width'];
    }

    private static function langAttr(array $c, string $code): array
    {
        $dom = self::dom($code);
        $lang = '';
        if ($dom !== null) {
            $node = $dom['xp']->query('//html[@lang]')->item(0);
            $lang = $node instanceof \DOMElement ? trim($node->getAttribute('lang')) : '';
        }
        if ($lang === '') {
            return [false, 0.0, 'no lang attribute on <html>'];
        }
        $want = strtolower(trim(self::str($c, 'value')));
        if ($want === '') {
            return [true, 1.0, 'lang="' . $lang . '"'];
        }
        $have = strtolower($lang);
        $pass = $have === $want || str_starts_with($have, $want . '-');
        return [$pass, $pass ? 1.0 : 0.25, 'lang="' . $lang . '" (expected ' . $want . ')'];
    }

    private static function dirRtl(string $code): array
    {
        $dom = self::dom($code);
        if ($dom !== null) {
            foreach (['html', 'body'] as $tag) {
                $node = $dom['xp']->query("//{$tag}[@dir]")->item(0);
                if ($node instanceof \DOMElement && strtolower(trim($node->getAttribute('dir'))) === 'rtl') {
                    return [true, 1.0, "dir=\"rtl\" on <{$tag}>"];
                }
            }
        }
        if (preg_match('/direction\s*:\s*rtl/i', $code)) {
            return [false, 0.5, 'RTL only via CSS direction (no dir="rtl" on <html> or <body>)'];
        }
        return [false, 0.0, 'no dir="rtl" on <html> or <body>'];
    }

    private static function htmlParses(array $c, string $code): array
    {
        $max = 5;
        foreach (['max_errors', 'max', 'threshold', 'value'] as $k) {
            if (isset($c[$k]) && is_numeric($c[$k])) {
                $max = max(0, (int) $c[$k]);
                break;
            }
        }
        $dom = self::dom($code);
        if ($dom === null) {
            return [false, 0.0, 'document could not be parsed'];
        }
        if ($dom['xp']->query('//body//*|//head//*')->length === 0) {
            return [false, 0.0, 'no HTML elements found'];
        }
        $errors = $dom['errors'];
        $n = count($errors);
        if (self::unclosedScript($code)) {
            $n++;
            $errors[] = 'unterminated <script> element (output cut off?)';
        }
        $pass = $n <= $max;
        $score = $pass ? 1.0 : max(0.0, 1 - ($n - $max) / ($max + 10));
        $detail = "{$n} parse error(s) (max {$max})" . ($errors ? ': ' . implode('; ', array_slice($errors, 0, 3)) : '');
        return [$pass, $score, $detail];
    }

    private static function singleFile(string $code, string $outputType): array
    {
        $bad = [];
        foreach (self::references($code, $outputType) as $ref) {
            if (self::isLocalRef($ref)) {
                $bad[$ref] = true;
            }
        }
        if (!$bad) {
            return [true, 1.0, 'no references to local files'];
        }
        $refs = array_map(static fn($r) => mb_substr($r, 0, 60), array_keys($bad));
        return [false, 0.0, 'local file references: ' . implode(', ', array_slice($refs, 0, 6)) . (count($refs) > 6 ? ' …' : '')];
    }

    private static function regexCount(array $c, string $code): array
    {
        $re = self::compile(self::str($c, 'pattern'), !empty($c['ci']), self::str($c, 'flags'));
        if ($re === null) {
            return [false, 0.0, 'invalid pattern'];
        }
        $n = self::countMatches($re, $code);
        if (is_string($n)) {
            return [false, 0.0, $n];
        }
        $min = max(0, (int) ($c['min'] ?? 1));
        $max = isset($c['max']) && is_numeric($c['max']) ? (int) $c['max'] : null;
        $pass = $n >= $min && ($max === null || $n <= $max);
        $score = ($max !== null && $n > $max) ? 0.0 : ($min > 0 ? min(1.0, $n / $min) : 1.0);
        return [$pass, $score, "{$n} match(es) (min {$min}" . ($max !== null ? ", max {$max}" : '') . ')'];
    }

    private static function jsParses(string $code, string $outputType): array
    {
        if ($outputType !== 'html') {
            [$ok, $msg] = self::jsBalanced($code);
            return [$ok, $ok ? 1.0 : 0.0, $ok ? 'balanced' : $msg];
        }
        $scripts = self::inlineScripts($code);
        if (!$scripts) {
            return [true, 1.0, 'no inline scripts'];
        }
        foreach ($scripts as $i => $s) {
            [$ok, $msg] = self::jsBalanced($s['code']);
            if (!$ok || !$s['closed']) {
                return [false, 0.0, 'script #' . ($i + 1) . ': ' . ($ok ? 'unterminated <script> element' : $msg)];
            }
        }
        return [true, 1.0, count($scripts) . ' inline script(s) balanced'];
    }

    /** Scalar option from a check definition as string (arrays/objects → default). */
    private static function str(array $c, string $key, string $default = ''): string
    {
        $v = $c[$key] ?? null;
        return is_scalar($v) ? (string) $v : $default;
    }

    /* ------------------------------------------------------------------ DOM */

    /**
     * Parse HTML once per run → ['doc','xp','errors'] (errors exclude HTML5-isms libxml does not know).
     * Script/style bodies are blanked (line count preserved) so JS strings like "</div>" are not errors.
     */
    private static function dom(string $code): ?array
    {
        $key = md5($code);
        if (array_key_exists($key, self::$domCache)) {
            return self::$domCache[$key];
        }
        if (strlen($code) > self::MAX_PARSE_BYTES || trim($code) === '') {
            return self::$domCache[$key] = null;
        }
        $blank = preg_replace_callback(
            '#(<(script|style)\b[^>]*>)(.*?)(</\2\s*>)#is',
            static fn($m) => $m[1] . str_repeat("\n", substr_count($m[3], "\n")) . $m[4],
            $code
        ) ?? $code;
        $html = mb_encode_numericentity($blank, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new \DOMDocument();
        $ok = $doc->loadHTML($html, LIBXML_NONET | LIBXML_COMPACT);
        $errors = [];
        foreach (libxml_get_errors() as $err) {
            // 801 unknown (HTML5) tag · 23/68 bare "&" · 26 HTML5-only named entity
            if (in_array($err->code, [801, 23, 68, 26], true)) {
                continue;
            }
            $errors[] = trim($err->message) . ' (line ' . $err->line . ')';
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return self::$domCache[$key] = null;
        }
        return self::$domCache[$key] = ['doc' => $doc, 'xp' => new \DOMXPath($doc), 'errors' => $errors];
    }

    /** Small CSS → XPath translator: groups (,), combinators (space > + ~), tag, *, #id, .class, [attr], [attr op val], a few pseudos. */
    public static function cssToXpath(string $css): ?string
    {
        $groups = [];
        foreach (self::splitTopLevel($css, ',') as $sel) {
            $x = self::selectorToXpath(trim($sel));
            if ($x === null) {
                return null;
            }
            $groups[] = $x;
        }
        return $groups ? implode(' | ', $groups) : null;
    }

    private static function selectorToXpath(string $sel): ?string
    {
        if ($sel === '') {
            return null;
        }
        $xpath = '//';
        $i = 0;
        $len = strlen($sel);
        $first = true;
        while ($i < $len) {
            $comb = ' ';
            $ws = false;
            while ($i < $len && ctype_space($sel[$i])) {
                $i++;
                $ws = true;
            }
            if ($i < $len && in_array($sel[$i], ['>', '+', '~'], true)) {
                $comb = $sel[$i];
                $i++;
                while ($i < $len && ctype_space($sel[$i])) {
                    $i++;
                }
            } elseif (!$ws && !$first) {
                return null;
            }
            if ($i >= $len) {
                return null;
            }
            $compound = self::compound($sel, $i);
            if ($compound === null) {
                return null;
            }
            [$tag, $preds] = $compound;
            if ($first) {
                if ($comb !== ' ') {
                    return null;
                }
                $xpath .= $tag . $preds;
            } else {
                $xpath .= match ($comb) {
                    '>' => '/' . $tag . $preds,
                    '+' => '/following-sibling::*[1][self::' . $tag . ']' . $preds,
                    '~' => '/following-sibling::' . $tag . $preds,
                    default => '//' . $tag . $preds,
                };
            }
            $first = false;
        }
        return $xpath;
    }

    /** Parse one compound selector at $i → [tag, predicates] (advances $i). */
    private static function compound(string $s, int &$i): ?array
    {
        $len = strlen($s);
        $tag = '*';
        if ($i < $len && preg_match('/\G(\*|[a-zA-Z][a-zA-Z0-9-]*)/', $s, $m, 0, $i)) {
            $tag = strtolower($m[1]);
            $i += strlen($m[1]);
        }
        $preds = '';
        $any = $tag !== '*' || ($i > 0 && $s[$i - 1] === '*');
        while ($i < $len && !ctype_space($s[$i]) && !in_array($s[$i], ['>', '+', '~'], true)) {
            $ch = $s[$i];
            if (($ch === '#' || $ch === '.') && preg_match('/\G[#.]((?:[\w-]|\\\\.)+)/u', $s, $m, 0, $i)) {
                $name = stripslashes($m[1]);
                $preds .= $ch === '#'
                    ? '[@id=' . self::lit($name) . ']'
                    : '[contains(concat(" ", normalize-space(@class), " "), ' . self::lit(' ' . $name . ' ') . ')]';
                $i += strlen($m[0]);
            } elseif ($ch === '[' && preg_match('/\G\[\s*([a-zA-Z_][\w:-]*)\s*(?:([~^$*|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*(i)?\s*)?\]/', $s, $m, 0, $i)) {
                $preds .= '[' . self::attrPredicate($m) . ']';
                $i += strlen($m[0]);
            } elseif ($ch === ':' && preg_match('/\G:(first-child|last-child|only-child|checked|disabled|empty|root)\b/', $s, $m, 0, $i)) {
                $preds .= match ($m[1]) {
                    'first-child' => '[not(preceding-sibling::*)]',
                    'last-child' => '[not(following-sibling::*)]',
                    'only-child' => '[not(preceding-sibling::*) and not(following-sibling::*)]',
                    'checked' => '[@checked or @selected]',
                    'disabled' => '[@disabled]',
                    'empty' => '[not(*) and not(normalize-space())]',
                    'root' => '[not(parent::*)]',
                };
                $i += strlen($m[0]);
            } else {
                return null;
            }
            $any = true;
        }
        return $any ? [$tag, $preds] : null;
    }

    private static function attrPredicate(array $m): string
    {
        $name = strtolower($m[1]);
        $attr = preg_match('/^[a-z_][\w-]*$/', $name) ? '@' . $name : '@*[name()=' . self::lit($name) . ']';
        $op = $m[2] ?? '';
        if ($op === '') {
            return $attr;
        }
        $val = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? ''));
        $ci = ($m[6] ?? '') === 'i';
        if ($ci) {
            $attr = 'translate(' . $attr . ', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';
            $val = strtolower($val);
        }
        $v = self::lit($val);
        return match ($op) {
            '=' => "{$attr}={$v}",
            '~=' => 'contains(concat(" ", normalize-space(' . $attr . '), " "), ' . self::lit(' ' . $val . ' ') . ')',
            '^=' => "starts-with({$attr}, {$v})",
            '$=' => "substring({$attr}, string-length({$attr}) - string-length({$v}) + 1)={$v}",
            '*=' => "contains({$attr}, {$v})",
            '|=' => "({$attr}={$v} or starts-with({$attr}, " . self::lit($val . '-') . '))',
            default => 'false()',
        };
    }

    /** XPath string literal (handles both quote kinds). */
    private static function lit(string $s): string
    {
        if (!str_contains($s, '"')) {
            return '"' . $s . '"';
        }
        if (!str_contains($s, "'")) {
            return "'" . $s . "'";
        }
        return 'concat("' . str_replace('"', '", \'"\', "', $s) . '")';
    }

    private static function splitTopLevel(string $s, string $sep): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $buf = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($quote !== null) {
                $quote = $ch === $quote ? null : $quote;
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '[' || $ch === '(') {
                $depth++;
            } elseif ($ch === ']' || $ch === ')') {
                $depth--;
            } elseif ($ch === $sep && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;
        return $parts;
    }

    /* ------------------------------------------------------------------ references */

    /** Every resource URL the document/script would load (navigation links excluded). */
    private static function references(string $code, string $outputType): array
    {
        $refs = [];
        if ($outputType === 'html' || preg_match('/<(html|head|body|script|link|img|style)\b/i', $code)) {
            $dom = self::dom($code);
            if ($dom !== null) {
                foreach ($dom['xp']->query('//*[@src or @href or @srcset or @poster or @data or @action or @formaction or @background or @*[name()="xlink:href"]]') as $el) {
                    /** @var \DOMElement $el */
                    $tag = strtolower($el->nodeName);
                    if ($tag === 'link' && !self::linkLoads($el->getAttribute('rel'))) {
                        continue;
                    }
                    foreach (self::RESOURCE_ATTRS as $attr) {
                        if (!$el->hasAttribute($attr) || (in_array($tag, ['a', 'area'], true) && $attr === 'href')) {
                            continue;
                        }
                        $val = trim($el->getAttribute($attr));
                        if ($attr === 'srcset') {
                            foreach (explode(',', $val) as $cand) {
                                $refs[] = trim(explode(' ', trim($cand))[0]);
                            }
                        } elseif (!($attr === 'data' && $tag !== 'object')) {
                            $refs[] = $val;
                        }
                    }
                }
                foreach ($dom['xp']->query('//*[@style]') as $el) {
                    $refs = array_merge($refs, self::cssUrls($el->getAttribute('style')));
                }
            }
            if (preg_match_all('#<style\b[^>]*>(.*?)(?:</style\s*>|$)#is', $code, $m)) {
                foreach ($m[1] as $css) {
                    $refs = array_merge($refs, self::cssUrls($css));
                }
            }
            foreach (self::inlineScripts($code) as $s) {
                $refs = array_merge($refs, self::jsUrls($s['code']));
            }
        } else {
            $refs = self::jsUrls($code);
        }
        return array_values(array_unique(array_filter($refs, static fn($r) => $r !== '')));
    }

    /** <link rel> values that make the browser fetch something (canonical/alternate/author … do not). */
    private static function linkLoads(string $rel): bool
    {
        $rel = strtolower(trim($rel));
        if ($rel === '') {
            return true;
        }
        $loading = ['stylesheet', 'icon', 'preload', 'modulepreload', 'prefetch', 'prerender', 'manifest', 'preconnect', 'dns-prefetch', 'apple-touch-icon', 'mask-icon', 'import'];
        return (bool) array_intersect(preg_split('/\s+/', $rel) ?: [], $loading);
    }

    private static function cssUrls(string $css): array
    {
        $refs = [];
        if (preg_match_all('/url\(\s*(["\']?)(.*?)\1\s*\)/is', $css, $m)) {
            $refs = $m[2];
        }
        if (preg_match_all('/@import\s+(["\'])(.*?)\1/i', $css, $m)) {
            $refs = array_merge($refs, $m[2]);
        }
        return array_map('trim', $refs);
    }

    /** URL string literals passed to loading APIs (fetch, import, workers, sockets, XHR, src/href assignment). */
    private static function jsUrls(string $js): array
    {
        $q = '(["\'`])([^"\'`\s]{1,2048})\1';
        $patterns = [
            '/\bfetch\s*\(\s*' . $q . '/',
            '/\bimport\s*\(\s*' . $q . '/',
            '/\b(?:import|export)\b[^;"\'`]*?\bfrom\s*' . $q . '/',
            '/\bimport\s+' . $q . '/',
            '/\bnew\s+(?:WebSocket|EventSource|Worker|SharedWorker|Audio)\s*\(\s*' . $q . '/',
            '/\bimportScripts\s*\(\s*' . $q . '/',
            '/\.open\s*\(\s*["\'][A-Za-z]+["\']\s*,\s*' . $q . '/',
            '/\bsendBeacon\s*\(\s*' . $q . '/',
            '/(?<!location)\.(?:src|href)\s*=\s*' . $q . '/',
            '/setAttribute\s*\(\s*["\'](?:src|href)["\']\s*,\s*' . $q . '/',
            '/\bloadScript\s*\(\s*' . $q . '/',
        ];
        $refs = [];
        foreach ($patterns as $re) {
            if (@preg_match_all($re, $js, $m)) {
                $refs = array_merge($refs, $m[2]);
            }
        }
        return $refs;
    }

    /** Inline (non-src) JavaScript blocks → list of ['code','closed']. */
    private static function inlineScripts(string $html): array
    {
        $out = [];
        foreach (self::scriptBlocks($html) as $b) {
            if (self::isJsScript($b['attrs'])) {
                $out[] = ['code' => $b['code'], 'closed' => $b['closed']];
            }
        }
        return $out;
    }

    /**
     * <script> elements scanned in document order, like a browser: each block runs to the next
     * </script>, so "<script>" inside a JS string does not open a new block. The last block may be
     * unclosed (output cut off). @return list<array{attrs: string, code: string, closed: bool}>
     */
    private static function scriptBlocks(string $html): array
    {
        $out = [];
        $pos = 0;
        while (count($out) < 500 && preg_match('#<script\b([^>]*)>#i', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1] + strlen($m[0][0]);
            if (!preg_match('#</script\s*>#i', $html, $e, PREG_OFFSET_CAPTURE, $start)) {
                $out[] = ['attrs' => $m[1][0], 'code' => substr($html, $start), 'closed' => false];
                break;
            }
            $out[] = ['attrs' => $m[1][0], 'code' => substr($html, $start, $e[0][1] - $start), 'closed' => true];
            $pos = $e[0][1] + strlen($e[0][0]);
        }
        return $out;
    }

    private static function unclosedScript(string $html): bool
    {
        $blocks = self::scriptBlocks($html);
        return $blocks !== [] && !end($blocks)['closed'];
    }

    private static function isJsScript(string $attrs): bool
    {
        if (preg_match('/\bsrc\s*=/i', $attrs)) {
            return false;
        }
        if (!preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $t)) {
            return true;
        }
        return in_array(strtolower($t[1]), ['module', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'text/babel'], true);
    }

    private static function externalHost(string $ref): ?string
    {
        $ref = trim($ref);
        if (!preg_match('#^(?:(https?|wss?|ftp):)?//([^/?\#\s]*)#i', $ref, $m)) {
            return null;
        }
        $host = strtolower(preg_replace('/^[^@]*@/', '', $m[2]) ?? '');
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if (in_array($host, ['www.w3.org'], true)) {
            return null;
        }
        return $host !== '' ? $host : '(unknown host)';
    }

    private static function hostAllowed(string $host, array $allow): bool
    {
        foreach ($allow as $a) {
            if ($host === $a || str_ends_with($host, '.' . $a)) {
                return true;
            }
        }
        return false;
    }

    private static function isLocalRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '' || $ref[0] === '#' || str_starts_with($ref, '//') || str_contains($ref, '${')) {
            return false;
        }
        return !preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref);
    }

    /* ------------------------------------------------------------------ JS heuristic */

    /**
     * Heuristic JS syntax check: brackets balanced outside strings, comments, template literals and
     * regex literals; no unterminated strings/comments. @return array{0: bool, 1: string}
     */
    public static function jsBalanced(string $js): array
    {
        $n = strlen($js);
        $stack = [];
        $line = 1;
        $i = 0;
        $last = '';
        $lastWord = '';
        $pairs = [')' => '(', ']' => '[', '}' => '{'];
        if (str_starts_with($js, '#!')) {
            $i = strpos($js, "\n") ?: $n;
        }
        while ($i < $n) {
            $ch = $js[$i];
            if ($ch === "\n") {
                $line++;
                $i++;
                continue;
            }
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            $next = $i + 1 < $n ? $js[$i + 1] : '';
            if ($ch === '/' && $next === '/') {
                $e = strpos($js, "\n", $i);
                $i = $e === false ? $n : $e;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $e = strpos($js, '*/', $i + 2);
                if ($e === false) {
                    return [false, "unterminated block comment (line {$line})"];
                }
                $line += substr_count($js, "\n", $i, $e - $i);
                $i = $e + 2;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $start = $line;
                $i++;
                while ($i < $n && $js[$i] !== $ch) {
                    if ($js[$i] === '\\') {
                        $line += ($js[$i + 1] ?? '') === "\n" ? 1 : 0;
                        $i += 2;
                        continue;
                    }
                    if ($js[$i] === "\n") {
                        return [false, "unterminated string (line {$start})"];
                    }
                    $i++;
                }
                if ($i >= $n) {
                    return [false, "unterminated string (line {$start})"];
                }
                $i++;
                $last = 'v';
                $lastWord = '';
                continue;
            }
            if ($ch === '`' || ($ch === '}' && $stack && end($stack)[0] === '${')) {
                if ($ch === '}') {
                    array_pop($stack);
                }
                $start = $line;
                $i++;
                $closed = false;
                while ($i < $n) {
                    $c2 = $js[$i];
                    if ($c2 === '\\') {
                        $line += ($js[$i + 1] ?? '') === "\n" ? 1 : 0;
                        $i += 2;
                        continue;
                    }
                    if ($c2 === "\n") {
                        $line++;
                    }
                    if ($c2 === '`') {
                        $closed = true;
                        $i++;
                        break;
                    }
                    if ($c2 === '$' && ($js[$i + 1] ?? '') === '{') {
                        $stack[] = ['${', $line];
                        $i += 2;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                if (!$closed) {
                    return [false, "unterminated template literal (line {$start})"];
                }
                $last = ($js[$i - 1] ?? '') === '`' ? 'v' : '(';
                $lastWord = '';
                continue;
            }
            if ($ch === '/') {
                $regexCtx = $last === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $last)
                    || in_array($lastWord, ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'], true);
                if ($regexCtx) {
                    $j = $i + 1;
                    $inClass = false;
                    $ok = false;
                    while ($j < $n && $js[$j] !== "\n") {
                        $c2 = $js[$j];
                        if ($c2 === '\\') {
                            $j += 2;
                            continue;
                        }
                        if ($c2 === '[') {
                            $inClass = true;
                        } elseif ($c2 === ']') {
                            $inClass = false;
                        } elseif ($c2 === '/' && !$inClass) {
                            $ok = true;
                            break;
                        }
                        $j++;
                    }
                    if ($ok) {
                        $i = $j + 1;
                        while ($i < $n && ctype_alpha($js[$i])) {
                            $i++;
                        }
                        $last = 'v';
                        $lastWord = '';
                        continue;
                    }
                }
                $last = '/';
                $lastWord = '';
                $i++;
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $stack[] = [$ch, $line];
            } elseif (isset($pairs[$ch])) {
                $top = array_pop($stack);
                if ($top === null) {
                    return [false, "unexpected '{$ch}' (line {$line})"];
                }
                if ($top[0] !== $pairs[$ch]) {
                    return [false, "mismatched '{$ch}' at line {$line} (opened '{$top[0]}' at line {$top[1]})"];
                }
            }
            if (ctype_alnum($ch) || $ch === '_' || $ch === '$') {
                $j = $i;
                while ($j < $n && (ctype_alnum($js[$j]) || $js[$j] === '_' || $js[$j] === '$')) {
                    $j++;
                }
                $lastWord = substr($js, $i, $j - $i);
                $last = 'v';
                $i = $j;
                continue;
            }
            $last = $ch === ')' || $ch === ']' ? 'v' : $ch;
            $lastWord = '';
            $i++;
        }
        if ($stack) {
            $top = end($stack);
            return [false, "unclosed '{$top[0]}' opened at line {$top[1]}"];
        }
        return [true, 'balanced'];
    }
}
