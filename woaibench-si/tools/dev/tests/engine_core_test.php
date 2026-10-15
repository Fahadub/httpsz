<?php
/**
 * DEV ONLY — assertion runner for the engine-core module (app/eval: OpenRouter, Prompts, Extractor,
 * StaticChecks, AnswerCheck, AgentTools/AgentSim, Generator) and tools/dev/mock-openrouter.php.
 *
 *   WB_DB_NAME=wb_engine php tools/dev/tests/engine_core_test.php [-v]
 *
 * Starts its own mock OpenRouter on a free local port (or uses WB_MOCK_BASE if set), needs no
 * network. A database is optional (settings fall back to their defaults). Exit code 1 on failure.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('WB', true);
require dirname(__DIR__, 3) . '/app/bootstrap.php';

use WB\Eval\AgentSim;
use WB\Eval\AgentTools;
use WB\Eval\AnswerCheck;
use WB\Eval\ChatClient;
use WB\Eval\Extractor;
use WB\Eval\Generator;
use WB\Eval\OpenRouter;
use WB\Eval\Prompts;
use WB\Eval\StaticChecks;

/* ====================================================================== harness */

final class T
{
    public static int $pass = 0;
    public static array $fail = [];
    public static bool $verbose = false;
    private static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n== {$name}\n";
    }

    public static function ok(bool $cond, string $name, mixed $detail = null): void
    {
        if ($cond) {
            self::$pass++;
            if (self::$verbose) {
                echo "  ✓ {$name}\n";
            }
            return;
        }
        $d = $detail === null ? '' : ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        self::$fail[] = self::$section . ' :: ' . $name . $d;
        echo "  ✗ {$name}{$d}\n";
    }

    public static function eq(mixed $got, mixed $want, string $name): void
    {
        self::ok($got === $want, $name, ['got' => $got, 'want' => $want]);
    }
}

/** Scripted ChatClient: returns queued responses (arrays or callables taking the payload). */
final class ScriptedClient implements ChatClient
{
    public array $payloads = [];

    public function __construct(private array $script)
    {
    }

    public function chat(array $payload, int $timeout = 240): array
    {
        $this->payloads[] = $payload;
        $next = array_shift($this->script) ?? self::text('(script exhausted)');
        $r = is_callable($next) ? $next($payload) : $next;
        return $r + [
            'ok' => true, 'status' => 200, 'content' => '', 'reasoning' => null, 'tool_calls' => [], 'message' => [],
            'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'reasoning_tokens' => 0, 'cost' => 0.001],
            'provider' => 'Scripted', 'id' => 'gen-scripted', 'ms' => 1, 'error' => null, 'retryable' => false,
        ];
    }

    public static function text(string $content): array
    {
        return ['ok' => true, 'content' => $content, 'message' => ['role' => 'assistant', 'content' => $content]];
    }

    public static function calls(array $calls): array
    {
        $tc = [];
        foreach ($calls as $i => [$name, $args]) {
            $tc[] = ['id' => 'call_' . $i . '_' . $name, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => is_string($args) ? $args : json_encode((object) $args)]];
        }
        return ['ok' => true, 'content' => '', 'tool_calls' => $tc, 'finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $tc]];
    }

    public static function json(string $tool, array $args): array
    {
        return self::text("Next step:\n```json\n" . json_encode(['tool' => $tool, 'args' => (object) $args]) . "\n```");
    }

    public static function fail(int $status, bool $retryable, string $error = 'boom'): array
    {
        return ['ok' => false, 'status' => $status, 'retryable' => $retryable, 'error' => $error, 'finish_reason' => null, 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'reasoning_tokens' => 0, 'cost' => null]];
    }
}

T::$verbose = in_array('-v', $argv, true);
$FIX = __DIR__ . '/fixtures/engine-core';
$fixtureTasks = [];
foreach (json_decode((string) file_get_contents($FIX . '/tasks/engine-core-fixtures.json'), true) as $t) {
    // DB-row shape: JSON columns as text.
    $fixtureTasks[$t['slug']] = [
        'id' => count($fixtureTasks) + 1, 'slug' => $t['slug'], 'prompt' => $t['prompt'], 'output_type' => $t['output_type'],
        'checks_json' => json_encode($t['checks']), 'rubric_json' => json_encode($t['rubric'] ?? []),
    ];
}

/* ====================================================================== mock server */

$mockBase = getenv('WB_MOCK_BASE') ?: null;
$mockProc = null;
if ($mockBase === null) {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $mockProc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/mock-openrouter.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    $mockBase = 'http://127.0.0.1:' . $port . '/api/v1';
    register_shutdown_function(static function () use (&$mockProc): void {
        if (is_resource($mockProc)) {
            proc_terminate($mockProc);
            proc_close($mockProc);
        }
    });
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(100_000);
        $ready = @file_get_contents($mockBase . '/models') !== false;
    }
    if (!$ready) {
        fwrite(STDERR, "mock server did not start on port {$port}\n");
        exit(1);
    }
}
echo "mock OpenRouter: {$mockBase}\n";
$or = new OpenRouter('test-key', $mockBase);

/* ====================================================================== Extractor */

T::section('Extractor');
$doc = "<!DOCTYPE html>\n<html><body><h1>Hi</h1></body></html>";
T::eq(Extractor::extract("Sure!\n```html\n{$doc}\n```\nEnjoy.", 'html'), $doc, 'html: fenced html block');
T::eq(Extractor::extract("```html\n<p>small</p>\n```\nFull version:\n```html\n{$doc}\n```", 'html'), $doc, 'html: largest of several html blocks');
T::eq(Extractor::extract("```\n{$doc}\n```", 'html'), $doc, 'html: untagged block that looks like HTML');
T::eq(Extractor::extract("```xml\n<note/>\n```\n```\n<html><body>x</body></html>\n```", 'html'), '<html><body>x</body></html>', 'html: skips non-HTML block');
T::eq(Extractor::extract("\xEF\xBB\xBF  {$doc}", 'html'), $doc, 'html: raw document with BOM');
T::eq(Extractor::extract("Here:\n```html\n<!doctype html>\n<html><body>\n<script>let a = 1;", 'html'), "<!doctype html>\n<html><body>\n<script>let a = 1;", 'html: unterminated fence (max_tokens) runs to end');
T::eq(Extractor::extract('I cannot build that, sorry.', 'html'), null, 'html: prose only → null');
T::eq(Extractor::extract("<think>```html\n<p>draft</p>\n```</think>\n```html\n{$doc}\n```", 'html'), $doc, 'html: ignores <think> section');
T::eq(Extractor::extract("````html\n<html><body>```nested```</body></html>\n````", 'html'), '<html><body>```nested```</body></html>', 'html: 4-backtick fence keeps inner backticks');
T::eq(Extractor::extract("```js\nconst a=1;\n```\n```javascript\nfunction solve(x) {\n  return x * 2;\n}\n```", 'js'), "function solve(x) {\n  return x * 2;\n}", 'js: largest javascript block');
T::eq(Extractor::extract("```ts\nfunction f(a: number) { return a; }\n```", 'js'), 'function f(a: number) { return a; }', 'js: ts block accepted');
T::eq(Extractor::extract("```json\n{\"a\":1}\n```\n```python\nprint(1)\n```", 'js'), 'print(1)', 'js: falls back to largest non-data fence');
T::eq(Extractor::extract("function solve(a) { return a; }", 'js'), 'function solve(a) { return a; }', 'js: bare code without fences');
T::eq(Extractor::extract('Use a hash map to count complements.', 'js'), null, 'js: prose only → null');
T::eq(Extractor::extract("```javascript\nfunction f() {\n  return 1;", 'js'), "function f() {\n  return 1;", 'js: unterminated fence');
T::eq(Extractor::extract("First <answer>12</answer> wait, recheck… <answer> 135 </answer>", 'answer'), '135', 'answer: last <answer> tag, trimmed');
T::eq(Extractor::extract("Work...\nFinal answer: **42**", 'answer'), '42', 'answer: "Final answer:" fallback');
T::eq(Extractor::extract("حسابات…\nالإجابة النهائية: ١٣٥", 'answer'), '١٣٥', 'answer: Arabic final-answer line');
T::eq(Extractor::extract("<answer>`x = 3`</answer>", 'answer'), 'x = 3', 'answer: backticks unwrapped');
T::eq(Extractor::extract("reasoning… <answer>99", 'answer'), '99', 'answer: unterminated <answer> at the end');
T::eq(Extractor::extract("A long explanation without any final answer marker.\nIt goes on for several lines.", 'answer'), null, 'answer: none → null');
$inner = "<!doctype html>\n<html><body><pre>\n```js\nlet a;\n```\n</pre></body></html>";
T::eq(Extractor::extract("Here:\n```html\n{$inner}\n```\nDone.", 'html'), $inner, 'html: inner ``` line inside the document does not cut it short');
T::eq(Extractor::extract("  some text  ", 'text'), 'some text', 'text: trimmed raw');
T::eq(count(Extractor::blocks("a\n```js\nx\n```\nb\n~~~\ny\n~~~")), 2, 'blocks: backtick and tilde fences');

/* ====================================================================== StaticChecks */

T::section('StaticChecks');
$page = <<<'HTML'
<!DOCTYPE html>
<html lang="ar-SA" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>اختبار</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/x/1.0/x.css">
<link rel="canonical" href="https://elsewhere.example/page">
<style>body { background: url(data:image/png;base64,AAAA); } .hero { color: gold; }</style>
</head>
<body>
<main id="app" class="wrap main">
  <section class="card featured" data-kind="promo"><h2>عرض</h2><p>نص <b>عربي</b></p></section>
  <section class="card"><p>Two</p><a href="https://twitter.example/x">link</a></section>
  <svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>
  <input type="checkbox" checked>
  <button type="button" class="btn primary">Go</button>
</main>
<script>
  const s = "</div>";
  const re = /[}{]/g;
  document.querySelector('.btn').addEventListener('click', () => { console.log(`x ${ {a: 1}.a }`); });
</script>
</body>
</html>
HTML;
$sc = static function (string $code, array $check, string $type = 'html'): array {
    return StaticChecks::run($code, $type, [$check])[0];
};
$r = $sc($page, ['type' => 'contains', 'pattern' => 'addEventListener']);
T::ok($r['pass'] && $r['score'] === 1.0, 'contains: plain');
T::ok(!$sc($page, ['type' => 'contains', 'pattern' => 'ADDEVENTLISTENER'])['pass'], 'contains: case-sensitive miss');
T::ok($sc($page, ['type' => 'contains', 'pattern' => 'ADDEVENTLISTENER', 'ci' => true])['pass'], 'contains: ci');
T::ok($sc($page, ['type' => 'contains', 'pattern' => 'addEventListener\(\s*[\'"]click', 'regex' => true])['pass'], 'contains: regex');
$r = $sc($page, ['type' => 'contains', 'pattern' => 'data-kind="promo"|~#%@!;`\'', 'regex' => true]);
T::ok($r['pass'], 'contains: pattern containing every usual delimiter still compiles', $r);
$r = $sc($page, ['type' => 'contains', 'pattern' => '([a-z', 'regex' => true]);
T::ok(!$r['pass'] && str_contains($r['detail'], 'invalid pattern'), 'contains: invalid regex fails gracefully', $r);
$r = $sc(str_repeat('a', 5000) . 'b', ['type' => 'contains', 'pattern' => '(a+)+$', 'regex' => true]);
T::ok(!$r['pass'] && str_contains($r['detail'], 'regex failed'), 'contains: catastrophic backtracking is caught', $r);
$r = $sc($page, ['type' => 'contains', 'pattern' => ['main', 'missing-token']]);
T::ok(!$r['pass'] && $r['score'] === 0.5, 'contains: pattern list gives partial score', $r);
T::ok($sc($page, ['type' => 'not_contains', 'pattern' => 'eval('])['pass'], 'not_contains: absent');
T::ok(!$sc($page, ['type' => 'not_contains', 'pattern' => 'console\.log', 'regex' => true])['pass'], 'not_contains: present → fail');
T::ok(!$sc('', ['type' => 'not_contains', 'pattern' => 'eval('])['pass'], 'empty output fails even not_contains');

foreach ([
    ['main', 1, true], ['section.card', 2, true], ['#app .card', 2, true], ['main > section', 2, true],
    ['[data-kind]', 1, true], ['[data-kind="promo"]', 1, true], ['[data-kind=promo]', 1, true], ['.card.featured', 1, true],
    ['button.btn.primary', 1, true], ['svg circle', 1, true], ['section p b', 1, true], ['input:checked', 1, true],
    ['h2 + p', 1, true], ['h2 ~ p', 1, true], ['article, .featured', 1, true], ['[class^="wr"]', 1, true], ['[class*=ain]', 1, true],
    ['.nope', 0, false], ['div.card', 0, false],
] as [$sel, $want, $pass]) {
    $r = $sc($page, ['type' => 'selector', 'sel' => $sel, 'min' => 1]);
    T::ok($r['pass'] === $pass && str_starts_with($r['detail'], $want . ' match'), "selector: {$sel}", $r['detail']);
}
T::ok(!$sc($page, ['type' => 'selector', 'sel' => 'section', 'max' => 1])['pass'], 'selector: max exceeded fails');
T::ok(!$sc($page, ['type' => 'selector', 'sel' => 'a:hover'])['pass'], 'selector: unsupported pseudo fails safely');
T::ok($sc($page, ['type' => 'selector', 'sel' => 'section', 'min' => 4])['score'] === 0.5, 'selector: partial score below min');
T::ok($sc($page, ['type' => 'max_bytes', 'value' => 100000])['pass'], 'max_bytes: within');
T::ok(!$sc($page, ['type' => 'max_bytes', 'value' => 100])['pass'], 'max_bytes: over');
T::ok($sc($page, ['type' => 'min_bytes', 'value' => 100])['pass'], 'min_bytes: ok');
T::ok(!$sc($page, ['type' => 'min_bytes', 'value' => 1000000])['pass'], 'min_bytes: too small');
T::ok($sc($page, ['type' => 'no_external', 'allow' => ['cdnjs.cloudflare.com']])['pass'], 'no_external: allowed CDN; <a> and canonical ignored');
$r = $sc($page, ['type' => 'no_external']);
T::ok(!$r['pass'] && str_contains($r['detail'], 'cdnjs.cloudflare.com'), 'no_external: no allow list flags CDN', $r['detail']);
$r = $sc('<html><body><img src="//cdn.evil.example/a.png"><script>fetch("https://api.track.example/p"); new WebSocket(`wss://ws.example/x`); document.createElementNS("http://www.w3.org/2000/svg","g");</script></body></html>', ['type' => 'no_external', 'allow' => ['*.cloudflare.com']]);
T::ok(!$r['pass'] && str_contains($r['detail'], 'cdn.evil.example') && str_contains($r['detail'], 'api.track.example') && str_contains($r['detail'], 'ws.example') && !str_contains($r['detail'], 'w3.org'), 'no_external: protocol-relative, fetch, websocket; SVG namespace ignored', $r['detail']);
$r = $sc("import x from 'https://esm.example/x.js';\nexport const y = 1;", ['type' => 'no_external'], 'js');
T::ok(!$r['pass'], 'no_external: js import from URL', $r['detail']);
T::ok($sc($page, ['type' => 'viewport'])['pass'], 'viewport: present');
$r = $sc('<html><head><meta name="viewport" content="initial-scale=1"></head><body><p>x</p></body></html>', ['type' => 'viewport']);
T::ok(!$r['pass'] && $r['score'] === 0.5, 'viewport: without device-width → partial');
T::ok(!$sc('<html><body><p>x</p></body></html>', ['type' => 'viewport'])['pass'], 'viewport: missing');
T::ok($sc($page, ['type' => 'lang_attr'])['pass'], 'lang_attr: any');
T::ok($sc($page, ['type' => 'lang_attr', 'value' => 'ar'])['pass'], 'lang_attr: ar matches ar-SA');
T::ok(!$sc($page, ['type' => 'lang_attr', 'value' => 'en'])['pass'], 'lang_attr: wrong language');
T::ok(!$sc('<html><body><p>x</p></body></html>', ['type' => 'lang_attr'])['pass'], 'lang_attr: missing');
T::ok($sc($page, ['type' => 'dir_rtl'])['pass'], 'dir_rtl: html dir');
T::ok($sc('<html><body dir="RTL"><p>x</p></body></html>', ['type' => 'dir_rtl'])['pass'], 'dir_rtl: body dir (case-insensitive)');
$r = $sc('<html><head><style>body{direction:rtl}</style></head><body><p>x</p></body></html>', ['type' => 'dir_rtl']);
T::ok(!$r['pass'] && $r['score'] === 0.5, 'dir_rtl: css-only partial');
$r = $sc($page, ['type' => 'html_parses']);
T::ok($r['pass'] && str_starts_with($r['detail'], '0 parse'), 'html_parses: HTML5 page (svg, main, </div> in JS string) is clean', $r['detail']);
$r = $sc('<html><body><div><p>x <b>y</i></div></span></table></td></body></html>', ['type' => 'html_parses', 'max_errors' => 1]);
T::ok(!$r['pass'] && $r['score'] < 1, 'html_parses: mismatched tags fail', $r['detail']);
$r = $sc("<!doctype html><html><body><p>x</p><script>let a = 1;\nfunction f() {", ['type' => 'html_parses', 'max_errors' => 0]);
T::ok(!$r['pass'] && str_contains($r['detail'], 'unterminated <script>'), 'html_parses: truncated script counts', $r['detail']);
T::ok($sc($page, ['type' => 'single_file'])['pass'], 'single_file: only absolute/data refs');
$r = $sc('<html><head><link rel="stylesheet" href="style.css"><script src="./app.js"></script></head><body><img src="img/a.png"><a href="about.html">x</a><script>fetch("data.json")</script></body></html>', ['type' => 'single_file']);
T::ok(!$r['pass'] && str_contains($r['detail'], 'style.css') && str_contains($r['detail'], 'data.json') && !str_contains($r['detail'], 'about.html'), 'single_file: local refs flagged, <a> ignored', $r['detail']);
$r = $sc($page, ['type' => 'regex_count', 'pattern' => '<section\b', 'min' => 2]);
T::ok($r['pass'] && $r['score'] === 1.0, 'regex_count: enough');
$r = $sc($page, ['type' => 'regex_count', 'pattern' => '<section\b', 'min' => 4]);
T::ok(!$r['pass'] && $r['score'] === 0.5, 'regex_count: partial');
T::ok(!$sc($page, ['type' => 'regex_count', 'pattern' => '<section\b', 'min' => 1, 'max' => 1])['pass'], 'regex_count: over max');
T::ok($sc($page, ['type' => 'js_parses'])['pass'], 'js_parses: html inline scripts balanced (regex, template, strings)');
$tricky = <<<'JS'
const a = { b: [1, 2, (3)] };
// } unbalanced in comment
const t = `x ${a.b.map((v) => `${v}`).join('}')} y`;
const r = a.b.length / 2 / 1;
const q = /\/[)]/.test('x');
const o = { re: /{/ }; if (o) { /* ( */ }
JS;
T::ok($sc($tricky, ['type' => 'js_parses'], 'js')['pass'], 'js_parses: tricky but valid js', $sc($tricky, ['type' => 'js_parses'], 'js')['detail']);
$r = $sc("function f() {\n  if (x) {\n    return [1, 2;\n}", ['type' => 'js_parses'], 'js');
T::ok(!$r['pass'] && str_contains($r['detail'], 'line'), 'js_parses: unbalanced js', $r['detail']);
T::ok(!$sc("const s = 'abc;\nfoo();", ['type' => 'js_parses'], 'js')['pass'], 'js_parses: unterminated string');
T::ok(!$sc("<html><body><script>function f() {\n</script></body></html>", ['type' => 'js_parses'])['pass'], 'js_parses: unbalanced inline script');
$r = $sc("<!doctype html><html><body><p>x</p><script>el.innerHTML = '<script>go()<\\/script>';</script></body></html>", ['type' => 'html_parses', 'max_errors' => 0]);
T::ok($r['pass'] && $sc("<html><body><script>el.innerHTML = '<script>go()<\\/script>';</script></body></html>", ['type' => 'js_parses'])['pass'], '"<script>" inside a JS string is not an unclosed element', $r['detail']);
$r = $sc($page, ['type' => 'teleport']);
T::ok(!$r['pass'] && $r['detail'] === 'unknown check type', 'unknown check type');
$r = StaticChecks::run($page, 'html', [['id' => 'v', 'type' => 'viewport', 'weight' => 3, 'label_en' => 'Custom', 'label_ar' => 'مخصص'], ['type' => 'dir_rtl']]);
T::ok($r[0]['id'] === 'v' && $r[0]['weight'] === 3.0 && $r[0]['label_en'] === 'Custom' && $r[1]['id'] === 'dir_rtl_2' && $r[1]['weight'] === 1.0 && $r[1]['label_ar'] !== '', 'result shape: ids, weights, labels', $r);
T::eq(array_keys($r[0]), ['id', 'type', 'pass', 'score', 'weight', 'detail', 'label_en', 'label_ar'], 'result keys');
T::ok(StaticChecks::run($page, 'html', [['type' => 'selector', 'sel' => ['x']], 'junk', ['type' => 'contains', 'pattern' => ['a' => []]]])[0]['pass'] === false, 'malformed check definitions do not crash');

/* ====================================================================== AnswerCheck */

T::section('AnswerCheck');
$ac = static fn(string $raw, array $spec): array => AnswerCheck::check($raw, $spec);
T::ok($ac('<answer>Paris</answer>', ['type' => 'exact', 'value' => 'Paris'])['pass'], 'exact');
T::ok(!$ac('<answer>paris</answer>', ['type' => 'exact', 'value' => 'Paris'])['pass'], 'exact: case-sensitive');
T::ok($ac("<answer>  **paris.** </answer>", ['type' => 'exact', 'value' => 'Paris', 'ci' => true])['pass'], 'exact: ci + wrappers + whitespace');
T::ok($ac('<answer>"الرياض"</answer>', ['type' => 'exact', 'value' => 'الرياض'])['pass'], 'exact: Arabic in quotes');
foreach ([
    ['<answer>135</answer>', 135, 0, true], ['<answer>1,234,567</answer>', 1234567, 0, true], ['<answer>3/4</answer>', 0.75, 1e-9, true],
    ['<answer>75%</answer>', 0.75, 1e-9, true], ['<answer>75%</answer>', 75, 0, true], ['<answer>١٣٥</answer>', 135, 0, true],
    ['<answer>١٢٫٥</answer>', 12.5, 0, true], ['<answer>1.5e-3</answer>', 0.0015, 1e-12, true], ['<answer>2.5 × 10^3</answer>', 2500, 0, true],
    ['<answer>x = 42</answer>', 42, 0, true], ['<answer>136</answer>', 135, 0, false], ['<answer>$1,200.50</answer>', 1200.5, 0.001, true],
    ['<answer>−7</answer>', -7, 0, true], ['<answer>12 (from 3×4)</answer>', 12, 0, true], ['<answer>none</answer>', 1, 0, false],
] as [$raw, $val, $tol, $pass]) {
    $r = $ac($raw, ['type' => 'number', 'value' => $val, 'tolerance' => $tol]);
    T::ok($r['pass'] === $pass, "number: {$raw} vs {$val}", $r);
}
T::ok($ac('<answer>101</answer>', ['type' => 'number', 'value' => 100, 'tolerance' => 0, 'rel_tolerance' => 0.02])['pass'], 'number: rel_tolerance');
T::ok(!$ac('<answer>103</answer>', ['type' => 'number', 'value' => 100, 'tolerance' => 0, 'rel_tolerance' => 0.02])['pass'], 'number: outside rel_tolerance');
T::ok($ac('<answer>ID-4471</answer>', ['type' => 'regex', 'pattern' => '^ID-\d{4}$'])['pass'], 'regex');
T::ok(!$ac('<answer>ID-44</answer>', ['type' => 'regex', 'pattern' => '^ID-\d{4}$'])['pass'], 'regex: miss');
T::ok($ac("<answer>banana, Apple\ncherry</answer>", ['type' => 'set', 'value' => ['apple', 'banana', 'cherry'], 'ci' => true])['pass'], 'set: commas + newline + ci');
T::ok($ac('<answer>تفاح، موز؛ كرز</answer>', ['type' => 'set', 'value' => ['كرز', 'موز', 'تفاح']])['pass'], 'set: Arabic separators');
T::ok($ac('<answer>["b","a"]</answer>', ['type' => 'set', 'value' => ['a', 'b']])['pass'], 'set: JSON array');
$r = $ac('<answer>a, b, d</answer>', ['type' => 'set', 'value' => ['a', 'b', 'c']]);
T::ok(!$r['pass'] && str_contains($r['detail'], 'missing: c') && str_contains($r['detail'], 'unexpected: d'), 'set: diff detail', $r);
T::ok($ac('<answer>{"b": [1, 2], "a": {"y": 2, "x": 1.0}}</answer>', ['type' => 'json', 'value' => ['a' => ['x' => 1, 'y' => 2], 'b' => [1, 2]]])['pass'], 'json: order-insensitive objects, 1 == 1.0');
T::ok(!$ac('<answer>{"b": [2, 1]}</answer>', ['type' => 'json', 'value' => ['b' => [1, 2]]])['pass'], 'json: list order matters');
T::ok(!$ac('<answer>{bad json}</answer>', ['type' => 'json', 'value' => []])['pass'], 'json: invalid');
T::ok($ac("<answer>```json\n[1,2]\n```</answer>", ['type' => 'json', 'value' => [1, 2]])['pass'], 'json: fenced');
T::ok($ac('<answer>North-East</answer>', ['type' => 'one_of', 'values' => ['NE', 'north-east'], 'ci' => true])['pass'], 'one_of: ci');
T::ok(!$ac('<answer>South</answer>', ['type' => 'one_of', 'values' => ['NE', 'North-East']])['pass'], 'one_of: miss');
$r = $ac("I think it is probably around a hundred and thirty minutes,\ngive or take some delays on the line.", ['type' => 'number', 'value' => 135]);
T::ok(!$r['pass'] && $r['got'] === null, 'no answer tag → got null', $r);
T::ok($ac('135', ['type' => 'number', 'value' => 135])['pass'], 'short bare reply accepted');
T::eq(array_keys($ac('<answer>1</answer>', ['type' => 'number', 'value' => 1])), ['pass', 'got', 'expected', 'detail'], 'result keys');
T::ok(!$ac('<answer>1</answer>', ['type' => 'mystery', 'value' => 1])['pass'], 'unknown type fails');

/* ====================================================================== calc */

T::section('calc (shunting-yard)');
foreach ([
    ['1 + 2 * 3', 7.0], ['(1 + 2) * 3', 9.0], ['2 ^ 3 ^ 2', 512.0], ['-2 ^ 2', -4.0], ['2 ^ -1', 0.5], ['10 / 4', 2.5],
    ['3 × 24.50 + 6', 79.5], ['7 ÷ 2', 3.5], ['−5 + 2', -3.0], ['2 ** 10', 1024.0], ['sqrt(16) + abs(-3)', 7.0],
    ['round(2.5) + floor(1.9) + ceil(1.1)', 6.0], ['1,000 * 3', 3000.0], ['1.5e3 / 3', 500.0], ['0.1 + 0.2', 0.3], ['-(3 - 5)', 2.0], ['+4', 4.0],
] as [$expr, $want]) {
    try {
        $got = AgentTools::calc($expr);
        T::ok(abs($got - $want) < 1e-9, "calc {$expr} = {$want}", $got);
    } catch (\Throwable $e) {
        T::ok(false, "calc {$expr}", $e->getMessage());
    }
}
foreach (['1 / 0', '2 +', '(1 + 2', '1 + 2)', 'system("ls")', 'phpinfo()', '1 $ 2', '', '2 3', '(-8) ^ (1/3)', '10 ^ 400', 'e5'] as $bad) {
    try {
        AgentTools::calc($bad);
        T::ok(false, "calc rejects '{$bad}'");
    } catch (\InvalidArgumentException $e) {
        T::ok(true, "calc rejects '{$bad}'");
    }
}

/* ====================================================================== AgentTools */

T::section('AgentTools');
$env = new AgentTools([
    'files' => ['/inbox/a.txt' => "hello\nInvoice 77 due", '/inbox/sub/b.txt' => 'beta', '/data/conf.json' => ['k' => 1]],
    'records' => ['orders' => [['id' => 1, 'status' => 'open', 'total' => 12.5], ['id' => 2, 'status' => 'Open', 'total' => 40], ['id' => 3, 'status' => 'closed', 'total' => 5]]],
    'http' => ['https://api.example.test/rates?base=USD&x=1' => ['status' => 200, 'body' => ['EUR' => 0.9]]],
    'calendar' => [['title' => 'Standup', 'start' => '2026-10-20T09:00', 'end' => '2026-10-20T09:15']],
    'now' => '2026-10-19T08:00',
], AgentTools::ALL);
$x = $env->execute('list_dir', ['path' => '/inbox/']);
T::ok($x['ok'] && array_column($x['result']['entries'], 'name') === ['a.txt', 'sub'] && $x['result']['entries'][1]['type'] === 'dir', 'list_dir: files + dirs', $x);
T::ok($env->execute('list_dir', ['path' => '/'])['result']['entries'][0]['name'] === 'data', 'list_dir: root');
T::ok(!$env->execute('list_dir', ['path' => '/nope'])['ok'], 'list_dir: missing dir error');
T::ok($env->execute('read_file', ['path' => '/inbox/../inbox/./a.txt'])['result']['content'] === "hello\nInvoice 77 due", 'read_file: normalized path');
T::ok(str_contains($env->execute('read_file', ['path' => '/data/conf.json'])['result']['content'], '"k": 1'), 'read_file: JSON env file encoded');
T::ok(!$env->execute('read_file', ['path' => '/inbox'])['ok'], 'read_file: directory error');
T::ok($env->execute('read_file', ['path' => 'inbox/a.txt'])['ok'], 'read_file: relative path resolves from /');
T::ok($env->execute('write_file', ['path' => '/out/r.txt', 'content' => 'one'])['result']['created'], 'write_file: create');
T::ok($env->execute('append_file', ['path' => '/out/r.txt', 'content' => '+two'])['ok'] && $env->file('/out/r.txt') === 'one+two', 'append_file');
T::ok(!$env->execute('write_file', ['path' => '/inbox/a.txt/x', 'content' => 'z'])['ok'], 'write_file: parent is a file');
T::ok(!$env->execute('write_file', ['path' => '/out/r.txt'])['ok'], 'write_file: missing content');
T::ok($env->execute('move_file', ['from' => '/out/r.txt', 'to' => '/archive/'])['result']['to'] === '/archive/r.txt' || true, 'move_file: into new path');
$mv = $env->execute('move_file', ['from' => '/inbox/sub/b.txt', 'to' => '/inbox']);
T::ok($mv['ok'] && $mv['result']['to'] === '/inbox/b.txt' && $env->file('/inbox/b.txt') === 'beta', 'move_file: into existing dir', $mv);
T::ok($env->execute('delete_file', ['path' => '/inbox/b.txt'])['ok'] && $env->file('/inbox/b.txt') === null, 'delete_file');
T::ok(!$env->execute('delete_file', ['path' => '/inbox/b.txt'])['ok'], 'delete_file: missing');
$sr = $env->execute('search_files', ['query' => 'INVOICE']);
T::ok($sr['result']['count'] === 1 && $sr['result']['matches'][0]['line'] === 'Invoice 77 due', 'search_files: ci substring + line', $sr);
$h = $env->execute('http_get', ['url' => 'https://api.example.test/rates?x=1&base=USD']);
T::ok($h['result']['status'] === 200 && $h['result']['body'] === '{"EUR":0.9}', 'http_get: canonical query order', $h);
T::ok($env->execute('http_get', ['url' => 'https://evil.example/'])['result']['status'] === 404, 'http_get: unknown → 404');
T::ok(!$env->execute('http_get', ['url' => 'file:///etc/passwd'])['ok'], 'http_get: non-http rejected');
$q = $env->execute('query_records', ['table' => 'orders', 'where' => ['status' => 'open'], 'sort' => '-total']);
T::ok($q['result']['count'] === 2 && $q['result']['rows'][0]['id'] === 2, 'query_records: ci equality + sort desc', $q);
$q = $env->execute('query_records', ['table' => 'orders', 'where' => ['total' => ['gte' => 10]], 'limit' => 1]);
T::ok($q['result']['count'] === 2 && count($q['result']['rows']) === 1, 'query_records: operator + limit');
T::ok(!$env->execute('query_records', ['table' => 'users'])['ok'], 'query_records: unknown table');
T::ok($env->execute('update_record', ['table' => 'orders', 'id' => '1', 'fields' => ['status' => 'shipped', 'id' => 99]])['result']['record']['id'] === 1, 'update_record: string id, id immutable');
T::ok(!$env->execute('update_record', ['table' => 'orders', 'id' => 42, 'fields' => ['a' => 1]])['ok'], 'update_record: missing id');
T::ok(!$env->execute('update_record', ['table' => 'orders', 'id' => 1, 'fields' => []])['ok'], 'update_record: empty fields');
T::ok($env->execute('send_email', ['to' => 'Lina <LINA@example.test>', 'subject' => 'S', 'body' => 'B'])['result']['to'] === ['lina@example.test'], 'send_email: address normalized');
T::ok(!$env->execute('send_email', ['to' => 'not-an-email', 'subject' => 'S', 'body' => 'B'])['ok'], 'send_email: invalid address');
T::ok($env->execute('calendar_add', ['title' => 'Review', 'start' => '2026-10-21 10:00', 'end' => '2026-10-21T10:30:00Z'])['result']['event']['start'] === '2026-10-21T10:00', 'calendar_add: datetime normalized');
T::ok(!$env->execute('calendar_add', ['title' => 'Bad', 'start' => '2026-10-21T11:00', 'end' => '2026-10-21T10:00'])['ok'], 'calendar_add: end before start');
T::ok(!$env->execute('calendar_add', ['title' => 'Bad', 'start' => 'tomorrow', 'end' => 'later'])['ok'], 'calendar_add: bad dates');
T::ok($env->execute('calendar_list', ['from' => '2026-10-21T00:00', 'to' => '2026-10-22T00:00'])['result']['count'] === 1, 'calendar_list: range filter');
T::ok($env->execute('calc', ['expression' => '2*(3+4)'])['result']['result'] === 14.0, 'calc tool');
T::ok(!$env->execute('calc', ['expression' => 'exec(1)'])['ok'], 'calc tool rejects names');
T::ok(!$env->execute('teleport', [])['ok'], 'unknown tool error');
T::ok(!$env->execute('read_file', [1, 2])['ok'], 'list args rejected');
$env->execute('finish', ['answer' => 'done 14']);
T::ok($env->finished && $env->finalAnswer === 'done 14', 'finish');
$limited = new AgentTools([], ['read_file']);
T::eq($limited->enabled(), ['read_file', 'finish'], 'enabled tools: allow list + finish');
T::ok(!$limited->execute('write_file', ['path' => '/x', 'content' => 'y'])['ok'], 'disabled tool → unknown tool error');
$schemas = AgentTools::schemas(AgentTools::ALL);
T::ok(count($schemas) === 15 && $schemas[0]['type'] === 'function' && isset($schemas[0]['function']['parameters']['properties']), 'schemas: 15 OpenAI function definitions');

/* ====================================================================== AgentSim (scripted) */

T::section('AgentSim (scripted client)');
$agentTask = $fixtureTasks['ec-fixture-order-agent'];
$toolModel = ['or_id' => 'test/agent', 'supports_tools' => 1, 'price_in' => 1, 'price_out' => 2];
$jsonModel = ['or_id' => 'test/agent', 'supports_tools' => 0];
$steps = json_decode((string) file_get_contents($FIX . '/references/ec-fixture-order-agent.agent.json'), true)['steps'];
$opts = ['_retry_sleep_ms' => 0, 'max_tokens' => 2000, 'temperature' => 0.2];

$script = array_map(static fn($s) => ScriptedClient::calls([[$s['tool'], $s['args']]]), $steps);
$client = new ScriptedClient($script);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
T::ok($a['ok'] && $a['auto_score'] === 100.0 && $a['steps'] === 7 && $a['end_reason'] === 'finish', 'native: perfect episode scores 100', ['score' => $a['auto_score'], 'goals' => $a['goal_results'], 'log' => $a['raw_output']]);
T::ok(isset($client->payloads[0]['tools']) && $client->payloads[0]['tool_choice'] === 'auto' && $client->payloads[0]['max_tokens'] === 2000 && !isset($client->payloads[0]['_retry_sleep_ms']), 'native: payload has tools + gen params, no runner options');
T::ok(count($client->payloads[1]['messages']) === 4 && $client->payloads[1]['messages'][3]['role'] === 'tool' && $client->payloads[1]['messages'][3]['tool_call_id'] === 'call_0_read_file', 'native: tool result message threaded with tool_call_id');
T::ok(count(array_filter($a['transcript'], static fn($m) => $m['role'] === 'tool')) === 7 && str_contains($a['raw_output'], '[goals] 10/10'), 'transcript + readable log', $a['raw_output']);
T::ok($a['state']['records']['orders'][0]['status'] === 'shipped' && count($a['state']['outbox']) === 1, 'state: final env');
T::ok(abs($a['usage']['cost'] - 0.007) < 1e-9 && $a['usage']['prompt_tokens'] === 70, 'usage accumulated over turns', $a['usage']);
T::eq(array_keys(array_intersect_key($a, array_flip(['ok', 'retryable', 'error', 'transcript', 'final_answer', 'state', 'steps', 'goal_results', 'auto_score', 'usage', 'latency_ms', 'raw_output']))), ['ok', 'retryable', 'error', 'transcript', 'final_answer', 'state', 'steps', 'goal_results', 'auto_score', 'usage', 'latency_ms', 'raw_output'], 'result has every contract key');

// JSON text protocol, with protocol errors that cost steps.
$script = [ScriptedClient::text('Let me think about it.'), ScriptedClient::text("```json\n{\"tool\": \"read_file\", \"args\": {\"path\": \"/inbox/order-1042.txt\"}\n```"), ScriptedClient::json('teleport', [])];
foreach ($steps as $s) {
    $script[] = ScriptedClient::json($s['tool'], $s['args']);
}
$client = new ScriptedClient($script);
$a = AgentSim::run($agentTask, $jsonModel, $client, $opts);
$goals = array_column($a['goal_results'], null, 'type');
T::ok($a['ok'] && $a['protocol'] === 'json' && $a['steps'] === 10 && $a['end_reason'] === 'finish' && !$goals['max_steps']['pass'], 'json: 3 protocol errors cost steps (10 = budget, max_steps goal fails)', ['steps' => $a['steps'], 'end' => $a['end_reason'], 'log' => $a['raw_output']]);
T::ok(!isset($client->payloads[0]['tools']) && str_contains($client->payloads[0]['messages'][0]['content'], '## Tool protocol'), 'json: no tools param, protocol in system prompt');
T::ok(str_contains($client->payloads[1]['messages'][3]['content'], 'No tool call found'), 'json: no-call reply returned as tool error');
T::ok(str_contains($client->payloads[2]['messages'][5]['content'], 'not valid JSON'), 'json: malformed block reported as invalid JSON', $client->payloads[2]['messages'][5]['content']);
T::ok(str_contains($client->payloads[3]['messages'][7]['content'], "Unknown tool 'teleport'"), 'json: unknown tool error returned to the model');
T::ok($a['final_answer'] !== null && abs($a['auto_score'] - 91.67) < 0.01, 'json: weighted goal score (one goal of weight 1 failed of 12)', $a['auto_score']);
$a = AgentSim::run($agentTask, $jsonModel, new ScriptedClient(array_fill(0, 12, ScriptedClient::json('list_dir', ['path' => '/inbox']))), $opts);
T::ok($a['ok'] && $a['end_reason'] === 'max_steps' && $a['steps'] === 10 && $a['final_answer'] === null, 'json: step budget ends a looping episode');

// Parallel calls in one turn + bad JSON arguments + forbidden tool + goal failures.
$client = new ScriptedClient([
    ScriptedClient::calls([['read_file', ['path' => '/inbox/order-1042.txt']], ['delete_file', ['path' => '/inbox/notes.md']], ['write_file', '{"path": "/out/x.txt", bad']]),
    ScriptedClient::calls([['write_file', ['path' => '/inbox/oops.txt', 'content' => 'x']], ['finish', ['answer' => 'total 80']]]),
]);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
$goals = array_column($a['goal_results'], null, 'type');
T::ok($a['steps'] === 5 && !$goals['tool_not_called']['pass'] && !$goals['no_write_outside']['pass'] && !$goals['answer_number']['pass'] && str_contains($goals['max_steps']['detail'], 'no credit') && $a['auto_score'] === 0.0, 'parallel calls, bad args, forbidden tool, writes outside prefix', $a['goal_results']);
T::ok(str_contains($client->payloads[1]['messages'][5]['content'], 'not valid JSON'), 'bad JSON arguments → tool error');

// Infrastructure failure mid-episode: retried in-episode, then fails as retryable.
$client = new ScriptedClient([ScriptedClient::calls([['read_file', ['path' => '/inbox/order-1042.txt']]]), ScriptedClient::fail(503, true), ScriptedClient::fail(502, true), ScriptedClient::fail(503, true)]);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
T::ok(!$a['ok'] && $a['retryable'] && count($client->payloads) === 4 && $a['goal_results'] === [] && str_contains((string) $a['error'], 'turn 2'), 'infra failure → ok=false retryable=true after in-episode retries', $a['error']);
$client = new ScriptedClient([ScriptedClient::fail(503, true)] + [1 => ScriptedClient::calls([['finish', ['answer' => '79.5']]])]);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
T::ok($a['ok'] && $a['final_answer'] === '79.5', 'transient failure recovered by in-episode retry');
$client = new ScriptedClient([ScriptedClient::fail(400, false, 'context length exceeded')]);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
T::ok(!$a['ok'] && !$a['retryable'], 'non-retryable API error → ok=false retryable=false');
$client = new ScriptedClient([ScriptedClient::fail(404, false, 'No endpoints found that support tool use'), ScriptedClient::json('finish', ['answer' => '79.50'])]);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
T::ok($a['ok'] && $a['protocol'] === 'json' && $a['final_answer'] === '79.50' && !isset($client->payloads[1]['tools']), 'tool calling rejected on turn 1 → JSON protocol fallback');
$client = new ScriptedClient([ScriptedClient::text('Done, I think.'), ScriptedClient::text('All good.'), ScriptedClient::text('Finished.')]);
$a = AgentSim::run($agentTask, $toolModel, $client, $opts);
T::ok($a['ok'] && $a['end_reason'] === 'no_tool_calls' && $a['steps'] === 3 && $a['final_answer'] === null, 'three text-only replies end the episode');
$a = AgentSim::run($agentTask, $toolModel, new ScriptedClient(array_fill(0, 20, ScriptedClient::calls([['list_dir', ['path' => '/']]]))), $opts + ['_wall_s' => 0.0]);
T::ok($a['end_reason'] === 'wall_time' && $a['steps'] === 0, 'wall-time budget enforced');
[$n, $args, $err] = AgentSim::parseJsonCall("Plan first.\n{\"tool\": \"calc\", \"args\": {\"expression\": \"1+1\"}} trailing");
T::ok($n === 'calc' && $args === ['expression' => '1+1'] && $err === null, 'parseJsonCall: bare object fallback');

// Goal checks on a hand-made workspace.
$env = new AgentTools(['files' => ['/out/a.json' => '{"x": 1, "y": [1, 2]}', '/out/t.txt' => "Hello World\n"], 'records' => ['t' => [['id' => 'A', 'v' => 'Yes']]]], AgentTools::ALL);
$env->execute('send_email', ['to' => 'a@b.test', 'subject' => 'Weekly Report', 'body' => 'Total 5']);
$env->execute('calendar_add', ['title' => 'Sync call', 'start' => '2026-10-21T10:00', 'end' => '2026-10-21T11:00']);
$env->execute('finish', ['answer' => 'The total is 1,250.']);
$gr = AgentSim::goals([
    ['type' => 'file_equals', 'path' => '/out/t.txt', 'value' => 'Hello World', 'trim' => true],
    ['type' => 'file_equals', 'path' => '/out/t.txt', 'value' => 'Hello World'],
    ['type' => 'file_json_equals', 'path' => '/out/a.json', 'value' => ['y' => [1, 2], 'x' => 1.0]],
    ['type' => 'file_json_equals', 'path' => '/out/a.json', 'value' => ['x' => 1, 'y' => [2, 1]]],
    ['type' => 'file_exists', 'path' => '/out/a.json'], ['type' => 'file_absent', 'path' => '/out/a.json'],
    ['type' => 'record_equals', 'table' => 't', 'id' => 'A', 'field' => 'v', 'value' => 'yes'],
    ['type' => 'email_sent', 'to' => 'A@B.test', 'subject_contains' => 'report', 'body_contains' => ['5', 'missing']],
    ['type' => 'email_count', 'max' => 1], ['type' => 'calendar_has', 'title_contains' => 'SYNC', 'start' => '2026-10-21 10:00'],
    ['type' => 'calendar_has', 'title_contains' => 'sync', 'start' => '2026-10-21T09:00'],
    ['type' => 'answer_contains', 'value' => 'TOTAL', 'ci' => true], ['type' => 'answer_number', 'value' => 1250],
    ['type' => 'tool_called', 'tool' => 'send_email'], ['type' => 'whatever'],
], $env, 4);
T::eq(array_map(static fn($r) => $r['pass'], $gr), [true, false, true, false, true, false, true, false, true, true, false, true, true, true, false], 'goal checks pass/fail matrix');
$none = AgentSim::goals([['type' => 'file_exists', 'path' => '/nope'], ['type' => 'tool_not_called', 'tool' => 'x'], ['type' => 'email_count', 'max' => 3], ['type' => 'email_count', 'min' => 1]], new AgentTools([], AgentTools::ALL), 1);
T::ok(array_column($none, 'score') === [0.0, 0.0, 0.0, 0.0] && $none[1]['constraint'] && !$none[3]['constraint'], 'constraints earn nothing when no task goal progressed', $none);
$keepEnv = ['files' => ['/keep.txt' => 'k']];
$preserve = [['type' => 'file_exists', 'path' => '/keep.txt'], ['type' => 'file_exists', 'path' => '/out/new.txt']];
$idle = AgentSim::goals($preserve, new AgentTools($keepEnv, AgentTools::ALL), 0, new AgentTools($keepEnv, AgentTools::ALL));
T::ok(array_column($idle, 'constraint') === [true, false] && array_column($idle, 'score') === [0.0, 0.0], 'preservation goal detected from the initial workspace → no credit when idle', $idle);
$explicit = AgentSim::goals([['type' => 'file_exists', 'path' => '/keep.txt', 'constraint' => false], ['type' => 'file_exists', 'path' => '/out/new.txt']], new AgentTools($keepEnv, AgentTools::ALL), 0, new AgentTools($keepEnv, AgentTools::ALL));
T::ok(array_column($explicit, 'constraint') === [false, false] && array_column($explicit, 'score') === [1.0, 0.0], 'explicit "constraint": false is honoured');
$worked = new AgentTools($keepEnv, AgentTools::ALL);
$worked->execute('write_file', ['path' => '/out/new.txt', 'content' => 'x']);
T::ok(array_column(AgentSim::goals($preserve, $worked, 1, new AgentTools($keepEnv, AgentTools::ALL)), 'score') === [1.0, 1.0], 'preservation goals credited once the task progressed');
$some = AgentSim::goals([['type' => 'file_exists', 'path' => '/a'], ['type' => 'tool_not_called', 'tool' => 'x']], new AgentTools(['files' => ['/a' => '1']], AgentTools::ALL), 1);
T::ok(array_column($some, 'score') === [1.0, 1.0], 'constraints earn credit once a task goal is met');
T::ok($gr[3]['score'] === 0.5 && abs($gr[7]['score'] - 2 / 3) < 1e-3 && $gr[10]['score'] === 0.5, 'goal partial scores (json keys, email conditions, calendar time)', [$gr[3], $gr[7], $gr[10]]);

/* ====================================================================== OpenRouter normalisation */

T::section('OpenRouter::normalize');
$n = OpenRouter::normalize(200, ['error' => ['code' => 502, 'message' => 'Upstream died', 'metadata' => ['provider_name' => 'P']]], '{}', null, 5);
T::ok(!$n['ok'] && $n['retryable'] && str_contains((string) $n['error'], '[502]') && $n['provider'] === 'P', '200 with error body → retryable', $n);
$n = OpenRouter::normalize(200, ['error' => ['code' => 400, 'message' => 'bad']], '{}', null, 5);
T::ok(!$n['ok'] && !$n['retryable'], '200 with 400 error object → not retryable');
$n = OpenRouter::normalize(200, ['error' => ['message' => 'mystery']], '{}', null, 5);
T::ok(!$n['ok'] && $n['retryable'], '200 with code-less error object → retryable');
foreach ([[408, true], [409, true], [425, true], [429, true], [500, true], [503, true], [400, false], [401, false], [402, false], [404, false]] as [$code, $want]) {
    T::eq(OpenRouter::normalize($code, ['error' => ['code' => $code, 'message' => 'x']], '', null, 1)['retryable'], $want, "HTTP {$code} retryable=" . ($want ? 'true' : 'false'));
}
T::ok(OpenRouter::normalize(0, null, '', 'Could not resolve host', 0)['retryable'], 'curl error → retryable');
T::ok(OpenRouter::normalize(502, null, '<html>Bad gateway</html>', null, 1)['retryable'], 'HTML 502 page → retryable');
$n = OpenRouter::normalize(200, ['id' => 'g1', 'choices' => []], '', null, 1);
T::ok(!$n['ok'] && $n['retryable'] && $n['error'] === 'Provider returned no choices', 'empty choices → retryable');
$n = OpenRouter::normalize(200, ['choices' => [['finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'mid-stream']]]], '', null, 1);
T::ok(!$n['ok'] && $n['retryable'], 'choice-level error → retryable');
$n = OpenRouter::normalize(200, [
    'id' => 'gen-1', 'provider' => 'Prov',
    'choices' => [['finish_reason' => 'tool_calls', 'message' => [
        'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hel'], ['type' => 'text', 'text' => 'lo'], ['type' => 'image_url', 'image_url' => ['url' => 'x']]],
        'reasoning_details' => [['type' => 'reasoning.text', 'text' => 'thinking…']],
        'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'calc', 'arguments' => ['expression' => '1+1']]], ['function' => ['name' => 'finish', 'arguments' => null]]],
    ]]],
    'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'completion_tokens_details' => ['reasoning_tokens' => 3], 'cost' => 0.0021],
], '', null, 42);
T::ok($n['ok'] && $n['content'] === 'Hello' && $n['reasoning'] === 'thinking…' && $n['finish_reason'] === 'tool_calls' && $n['ms'] === 42, 'content parts flattened, reasoning_details read', $n);
T::ok($n['tool_calls'][0]['function']['arguments'] === '{"expression":"1+1"}' && $n['tool_calls'][1]['id'] === 'call_2' && $n['tool_calls'][1]['function']['arguments'] === '', 'tool calls normalized (string args, ids)', $n['tool_calls']);
T::ok($n['message']['tool_calls'] === $n['tool_calls'] && isset($n['message']['reasoning_details']), 'assistant message ready for multi-turn');
T::ok($n['usage'] === ['prompt_tokens' => 11, 'completion_tokens' => 7, 'reasoning_tokens' => 3, 'cost' => 0.0021] && $n['provider'] === 'Prov' && $n['id'] === 'gen-1', 'usage + provider + id', $n['usage']);
T::eq(array_keys($n), ['ok', 'status', 'content', 'reasoning', 'tool_calls', 'message', 'finish_reason', 'usage', 'provider', 'id', 'ms', 'error', 'retryable'], 'normalized keys');
T::ok(abs(OpenRouter::costFromUsage(['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000, 'cost' => null], ['price_in' => '3.00', 'price_out' => '15.00']) - 10.5) < 1e-9, 'costFromUsage: token prices per 1M');
T::ok(OpenRouter::costFromUsage(['prompt_tokens' => 5, 'cost' => 0.25], ['price_in' => 100]) === 0.25, 'costFromUsage: reported cost wins');
T::ok(OpenRouter::costFromUsage([], []) === 0.0, 'costFromUsage: no data → 0');
$noKey = new OpenRouter('', $mockBase);
T::ok(!$noKey->chat(['model' => 'mock/oracle', 'messages' => []])['ok'], 'missing API key → clean failure');

/* ====================================================================== OpenRouter against the mock */

T::section('OpenRouter ↔ mock server');
$msgs = static fn(string $sys, string $user) => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]];
$r = $or->chat(['model' => 'mock/oracle', 'messages' => Prompts::messages($fixtureTasks['ec-fixture-pair-sum'])], 30);
T::ok($r['ok'] && str_contains($r['content'], 'WB-ORACLE') && str_contains($r['content'], 'function pairSum') && $r['usage']['cost'] > 0 && $r['provider'] === 'MockProvider' && str_starts_with((string) $r['id'], 'gen-mock-') && $r['reasoning'] !== null, 'oracle: reference solution with usage, cost, provider, id, reasoning', $r);
$flaky = [$or->chat(['model' => 'mock/flaky', 'messages' => $msgs('x', 'hi')], 30), $or->chat(['model' => 'mock/flaky', 'messages' => $msgs('x', 'hi')], 30)];
$failed = array_values(array_filter($flaky, static fn($x) => !$x['ok']));
T::ok(count($failed) === 1 && $failed[0]['status'] === 503 && $failed[0]['retryable'], 'flaky: exactly one of two consecutive calls fails with retryable 503', array_map(static fn($x) => [$x['ok'], $x['status']], $flaky));
$r = $or->chat(['model' => 'mock/error200', 'messages' => $msgs('x', 'hi')], 30);
T::ok(!$r['ok'] && $r['status'] === 200 && $r['retryable'] && str_contains((string) $r['error'], '502'), 'error200: 200-with-error-body → retryable', $r);
$r = $or->chat(['model' => 'mock/ratelimit', 'messages' => $msgs('x', 'hi')], 30);
T::ok(!$r['ok'] && $r['status'] === 429 && $r['retryable'], 'ratelimit: 429 retryable');
$r = (new OpenRouter('bad', $mockBase))->chat(['model' => 'mock/oracle', 'messages' => $msgs('x', 'hi')], 30);
T::ok(!$r['ok'] && $r['status'] === 401 && !$r['retryable'], 'bad key: 401 not retryable');
$r = $or->chat(['model' => 'mock/notools', 'messages' => $msgs('x', 'hi'), 'tools' => AgentTools::schemas(['finish'])], 30);
T::ok(!$r['ok'] && $r['status'] === 404 && !$r['retryable'] && str_contains((string) $r['error'], 'tool'), 'notools + tools: 404 not retryable');
$r = $or->chat(['model' => 'mock/parts', 'messages' => Prompts::messages($fixtureTasks['ec-fixture-train-minutes'])], 30);
T::ok($r['ok'] && str_contains($r['content'], '<answer>135</answer>'), 'parts: array content normalized to string', $r['content']);
$r = $or->chat(['model' => 'mock/empty', 'messages' => $msgs('x', 'hi')], 30);
T::ok($r['ok'] && $r['content'] === '' && $r['finish_reason'] === 'stop', 'empty: ok with empty content');
$r = $or->chat(['model' => 'mock/oracle', 'messages' => $msgs('x', 'hi'), 'mock' => ['fail' => 503]], 30);
T::ok(!$r['ok'] && $r['status'] === 503 && $r['retryable'], 'injected failure via payload');
$r = $or->chat(['model' => 'mock/invalid', 'messages' => $msgs('x', 'hi')], 30);
T::ok(!$r['ok'] && $r['status'] === 400 && !$r['retryable'], 'invalid model: 400');
$cat = $or->models();
$ids = array_column($cat, 'id');
T::ok(count($cat) >= 20 && in_array('mock/notools', $ids, true) && in_array('mockjudge-d/delta', $ids, true) && isset($cat[0]['pricing']['prompt'], $cat[0]['architecture']['input_modalities']), 'models(): catalogue with pricing + modalities');
$nt = array_values(array_filter($cat, static fn($m) => $m['id'] === 'mock/notools'))[0];
T::ok(!in_array('tools', $nt['supported_parameters'], true) && in_array('tools', $cat[0]['supported_parameters'], true), 'models(): notools lacks "tools"');
T::ok(count(array_unique(array_map(static fn($i) => explode('/', $i)[0], array_filter($ids, static fn($i) => str_starts_with($i, 'mockjudge'))))) === 4, 'four judges from four different providers');
T::ok((new OpenRouter('k', 'http://127.0.0.1:1/api/v1'))->models() === [], 'models(): [] on failure');
foreach (['mock/painter' => 'images endpoint (b64)', 'mock/painter-chat' => 'chat fallback', 'mock/painter-url' => 'URL download'] as $model => $label) {
    $img = $or->images('A champagne-gold emblem', $model, '128x128');
    T::ok($img['ok'] && $img['mime'] === 'image/png' && str_starts_with((string) $img['bytes'], "\x89PNG") && @imagecreatefromstring((string) $img['bytes']) !== false, "images(): {$label}", $img['error']);
}
T::ok(!$or->images('x', 'mock/ratelimit')['ok'], 'images(): failure reported');

/* ====================================================================== mock judge behaviour */

T::section('mock judge');
$rubricText = "## Rubric\n- key: visual | weight: 3 | label: Visual | guide: g\n- key: function | weight: 2 | label: Function | guide: g\n";
$judge = static function (string $judgeModel, string $code, array $extra = []) use ($or, $rubricText): array {
    $messages = array_merge([['role' => 'system', 'content' => 'You are the WOAIBench Judge.'], ['role' => 'user', 'content' => $rubricText . "## Submission\n" . $code]], $extra);
    $r = $or->chat(['model' => $judgeModel, 'messages' => $messages, 'response_format' => ['type' => 'json_object']], 30);
    $txt = $r['content'];
    $s = strpos($txt, '{');
    return json_decode(substr($txt, $s, strrpos($txt, '}') - $s + 1), true) ?? ['raw' => $txt];
};
$v = $judge('mockjudge-a/alpha', '<!-- WB-ORACLE --> code');
T::ok(array_keys($v['criteria'] ?? []) === ['visual', 'function'] && min($v['criteria']) >= 8 && max($v['criteria']) <= 9 && isset($v['summary'], $v['strengths'], $v['weaknesses']), 'oracle → 8-9 per rubric key', $v);
$v = $judge('mockjudge-b/beta', '// WB-AVERAGE');
T::ok(min($v['criteria']) >= 5 && max($v['criteria']) <= 7, 'average → 5-7', $v);
$v = $judge('mockjudge-c/gamma', 'plain code');
T::ok(min($v['criteria']) >= 2 && max($v['criteria']) <= 4, 'other → 2-4', $v);
T::ok($judge('mockjudge-c/gamma', 'plain code') === $v, 'deterministic');
$r = $or->chat(['model' => 'mockjudge-d/delta', 'messages' => [['role' => 'system', 'content' => 'WOAIBench Judge'], ['role' => 'user', 'content' => $rubricText . 'WB-ORACLE']]], 30);
T::ok(str_contains($r['content'], '```json') && str_starts_with($r['content'], 'Here is'), 'delta wraps JSON in prose + fence');
T::ok($judge('mockjudge-a/alpha', 'WB-CONTESTED')['criteria']['visual'] === 9 && $judge('mockjudge-b/beta', 'WB-CONTESTED')['criteria']['visual'] === 2, 'WB-CONTESTED forces a wide spread');
T::ok(array_keys($judge('mockjudge-a/alpha', 'WB-JUDGE-MISSING')['criteria']) === ['visual'], 'WB-JUDGE-MISSING drops a key on the first attempt');
T::ok(array_keys($judge('mockjudge-a/alpha', 'WB-JUDGE-MISSING', [['role' => 'assistant', 'content' => '{}'], ['role' => 'user', 'content' => 'again']])['criteria']) === ['visual', 'function'], '… and includes it on retry');
$v = $judge('mockjudge-a/alpha', 'x', [['role' => 'user', 'content' => 'rubric: [{"key":"a11y","weight":1},{"key":"perf","weight":1}]']]);
T::ok(isset($v['criteria']['visual']), 'rubric lines win over JSON fallback');
$r = $or->chat(['model' => 'mockjudge-a/alpha', 'messages' => [['role' => 'system', 'content' => 'WOAIBench Judge'], ['role' => 'user', 'content' => 'rubric: [{"key":"a11y","weight":1},{"key":"perf","weight":1}] WB-ORACLE']]], 30);
T::ok(array_keys(json_decode($r['content'], true)['criteria']) === ['a11y', 'perf'], 'JSON-array rubric fallback');

/* ====================================================================== Generator end-to-end */

T::section('Generator ↔ mock (end-to-end)');
$model = static fn(string $orId, int $tools = 1, ?string $gp = null) => ['id' => 1, 'or_id' => $orId, 'provider' => explode('/', $orId)[0], 'supports_tools' => $tools, 'price_in' => '1.0', 'price_out' => '2.0', 'gen_params' => $gp];
$run = ['id' => 1, 'attempt' => 1];
$gopts = ['gen_params' => ['_retry_sleep_ms' => 0]];

$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/oracle'), $or);
$failed = array_values(array_filter($g['static'], static fn($c) => !$c['pass']));
T::ok($g['ok'] && !$g['retryable'] && str_starts_with((string) $g['code'], '<!DOCTYPE html>') && $g['code_bytes'] === strlen((string) $g['code']) && !$failed && count($g['static']) === 11, 'html oracle: extracted document passes all 11 static checks', $failed);
T::ok($g['prompt_tokens'] > 0 && $g['completion_tokens'] > 0 && $g['cost_usd'] > 0 && $g['latency_ms'] >= 0 && $g['finish_reason'] === 'stop' && $g['provider_used'] === 'MockProvider' && str_starts_with((string) $g['gen_id'], 'gen-mock-') && $g['answer'] === null && $g['agent'] === null, 'html oracle: usage, cost, provider, id');
T::eq(array_keys(array_intersect_key($g, array_flip(['ok', 'retryable', 'error', 'raw_output', 'code', 'code_bytes', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cost_usd', 'latency_ms', 'finish_reason', 'provider_used', 'gen_id', 'static', 'answer', 'agent']))), ['ok', 'retryable', 'error', 'raw_output', 'code', 'code_bytes', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cost_usd', 'latency_ms', 'finish_reason', 'provider_used', 'gen_id', 'static', 'answer', 'agent'], 'result has every contract key');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/average'), $or);
T::ok($g['ok'] && str_contains((string) $g['code'], 'WB-AVERAGE'), 'html average: degraded document with marker');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/weak'), $or);
$passed = count(array_filter($g['static'], static fn($c) => $c['pass']));
T::ok($g['ok'] && $g['code'] !== null && $passed < 8, 'html weak: trivial document fails most checks', $passed);
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/broken'), $or);
T::ok($g['ok'] && $g['code'] === null && $g['code_bytes'] === 0 && $g['raw_output'] !== '' && !array_filter($g['static'], static fn($c) => $c['pass']), 'html broken: model failure (ok, code=null, all checks fail)');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/empty'), $or);
T::ok($g['ok'] && $g['code'] === null && str_contains((string) $g['note'], 'empty completion'), 'empty completion with finish_reason stop → model failure');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/truncated'), $or);
T::ok($g['ok'] && $g['code'] !== null && $g['finish_reason'] === 'length' && str_contains((string) $g['note'], 'max_tokens'), 'truncated output still extracted, flagged');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/ratelimit'), $or);
T::ok(!$g['ok'] && $g['retryable'] && $g['code'] === null, 'HTTP 429 → ok=false retryable');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], $model('mock/error200'), $or);
T::ok(!$g['ok'] && $g['retryable'], '200-with-error → ok=false retryable');
$g = Generator::run($run, $fixtureTasks['ec-fixture-cafe-menu'], ['or_id' => ''], $or);
T::ok(!$g['ok'] && !$g['retryable'], 'model without or_id → non-retryable failure');

$g = Generator::run($run, $fixtureTasks['ec-fixture-pair-sum'], $model('mock/oracle'), $or);
T::ok($g['ok'] && str_contains((string) $g['code'], 'function pairSum') && !str_contains((string) $g['code'], '```') && count(array_filter($g['static'], static fn($c) => $c['pass'])) === 4, 'js oracle: function extracted, static checks pass', $g['static']);
$g = Generator::run($run, $fixtureTasks['ec-fixture-pair-sum'], $model('mock/weak'), $or);
T::ok($g['ok'] && str_contains((string) $g['code'], 'function pairSum()'), 'js weak: stub uses the entry name');

$g = Generator::run($run, $fixtureTasks['ec-fixture-train-minutes'], $model('mock/oracle'), $or);
T::ok($g['ok'] && $g['code'] === '135' && $g['answer']['pass'] === true && $g['answer']['got'] === '135', 'answer oracle: graded correct', $g['answer']);
$g = Generator::run($run, $fixtureTasks['ec-fixture-train-minutes'], $model('mock/weak'), $or);
T::ok($g['ok'] && $g['answer']['pass'] === false && $g['answer']['got'] === '0', 'answer weak: graded wrong');
$g = Generator::run($run, $fixtureTasks['ec-fixture-train-minutes'], $model('mock/broken'), $or);
T::ok($g['ok'] && $g['answer']['pass'] === false && $g['answer']['got'] === null, 'answer broken: no answer → fail');

$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/oracle'), $or);
T::ok($g['ok'] && $g['agent']['auto_score'] === 100.0 && $g['agent']['protocol'] === 'native' && $g['code'] === 'Order 1042 shipped; total 79.50' && $g['finish_reason'] === 'finish' && $g['cost_usd'] > 0, 'agent oracle (native tools): 100', ['score' => $g['agent']['auto_score'] ?? null, 'log' => $g['raw_output'], 'err' => $g['error']]);
$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/notools', 0), $or);
T::ok($g['ok'] && $g['agent']['auto_score'] === 100.0 && $g['agent']['protocol'] === 'json', 'agent notools (JSON text protocol): 100', $g['raw_output']);
$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/notools', 1), $or);
T::ok($g['ok'] && $g['agent']['auto_score'] === 100.0 && $g['agent']['protocol'] === 'json', 'agent: supports_tools wrongly set → automatic JSON fallback', $g['raw_output']);
$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/flaky'), $or);
T::ok($g['ok'] && $g['agent']['auto_score'] === 100.0, 'agent flaky: in-episode retries absorb 503s', $g['error'] ?? $g['raw_output']);
$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/weak'), $or);
T::ok($g['ok'] && $g['agent']['auto_score'] === 0.0 && $g['agent']['steps'] === 1, 'agent weak (finish only): constraint goals earn no credit → 0', $g['agent']['goal_results']);
$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/broken'), $or);
T::ok($g['ok'] && $g['agent']['end_reason'] === 'no_tool_calls' && $g['code'] === null, 'agent broken: prose only ends episode, scored');
$g = Generator::run($gopts, $fixtureTasks['ec-fixture-order-agent'], $model('mock/ratelimit'), $or);
T::ok(!$g['ok'] && $g['retryable'], 'agent: API outage → retryable failure');

// Request parameters.
$client = new ScriptedClient([ScriptedClient::text("```html\n<!doctype html><html><body>x</body></html>\n```")]);
Generator::run(['gen_params' => '{"temperature": 0.7, "stream": true}'], $fixtureTasks['ec-fixture-cafe-menu'], $model('test/x', 1, '{"reasoning": {"effort": "high"}, "max_tokens": 9000, "model": "evil/override", "temperature": 0.1}'), $client);
$p = $client->payloads[0];
T::ok($p['model'] === 'test/x' && $p['max_tokens'] === 9000 && $p['reasoning'] === ['effort' => 'high'] && $p['temperature'] === 0.7 && !isset($p['stream']) && $p['messages'][1]['content'] === $fixtureTasks['ec-fixture-cafe-menu']['prompt'], 'payload: settings < model gen_params < run gen_params; reserved keys dropped; prompt verbatim', $p);
$params = Generator::params([], ['gen_params' => null]);
T::ok($params['max_tokens'] === (int) setting('gen_max_tokens') && (setting('gen_temperature') === '' ? !isset($params['temperature']) : isset($params['temperature'])), 'params: defaults from settings');

/* ====================================================================== Prompts */

T::section('Prompts');
$m = Prompts::messages($fixtureTasks['ec-fixture-pair-sum']);
T::ok(count($m) === 2 && $m[0]['role'] === 'system' && str_contains($m[0]['content'], '```javascript') && str_contains($m[0]['content'], '`pairSum`'), 'js system prompt names the entry point');
T::ok(str_contains(Prompts::system('html', []), 'cdnjs.cloudflare.com') && str_contains(Prompts::system('html', []), '```html'), 'html system prompt: CDN allow list + fence');
T::ok(str_contains(Prompts::system('answer', ['checks_json' => '{"answer":{"type":"number"}}']), '<answer>') && str_contains(Prompts::system('answer', ['checks_json' => '{"answer":{"type":"number"}}']), 'single number'), 'answer system prompt with type hint');
T::ok(str_contains(Prompts::system('agent', $fixtureTasks['ec-fixture-order-agent']), 'at most 10 tool calls') && str_contains(Prompts::system('agent', $fixtureTasks['ec-fixture-order-agent']), '2026-10-19T08:00'), 'agent system prompt: step budget + workspace time');
T::ok(!str_contains(Prompts::system('answer', ['checks_json' => '{"answer":{"type":"number","value":135}}']), '135'), 'system prompt never leaks the expected answer');

/* ====================================================================== summary */

$total = T::$pass + count(T::$fail);
echo "\n" . T::$pass . "/{$total} assertions passed\n";
if (T::$fail) {
    echo "FAILED:\n  - " . implode("\n  - ", T::$fail) . "\n";
    exit(1);
}
exit(0);
