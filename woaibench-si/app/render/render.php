<?php
/**
 * Rendering of untrusted model output (module: verifier).
 *
 * - wb_render_vt() / wb_render_vt_valid(): short-lived HMAC tokens that let a verifier (Station iframe
 *   or headless Playwright) open hold-out runs and the probe-instrumented document.
 * - wb_render_document(): sends the sandbox headers and the document for one run
 *   (html as-is, js as a test-harness page, everything else as escaped text).
 *
 * Every document is served with Http::sandboxHeaders(): the CSP `sandbox` directive gives it an opaque
 * origin even when it is opened top-level, so model code can never read our cookies or storage, nor
 * call same-origin endpoints. Load this file with require_once.
 */
declare(strict_types=1);

defined('WB') || exit;

/** Lifetime of a verification token in seconds. */
const WB_RENDER_VT_TTL = 900;

/** Output types that render as a live page and can be verified in a browser. */
const WB_RENDER_LIVE_TYPES = ['html', 'js'];

/** base64url without padding. */
function wb_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** HMAC signature part of a verification token. */
function wb_render_vt_sig(int $runId, string $lockToken, int $expiry): string
{
    return wb_b64url(hash_hmac('sha256', $runId . '|' . $lockToken . '|' . $expiry, app_secret(), true));
}

/**
 * Verification token for /render/{id}?verify=1&vt=… — "<expiry>.<base64url(HMAC)>", valid 15 minutes
 * and only while the run is leased (status verifying) with this lock token.
 */
function wb_render_vt(int $runId, string $lockToken): string
{
    $expiry = time() + WB_RENDER_VT_TTL;
    return $expiry . '.' . wb_render_vt_sig($runId, $lockToken, $expiry);
}

/** True when $vt is authentic, unexpired and matches the run's current verifying lease. */
function wb_render_vt_valid(int $runId, string $vt): bool
{
    if ($runId <= 0 || !preg_match('/^(\d{9,12})\.([A-Za-z0-9_-]{43})$/', $vt, $m)) {
        return false;
    }
    $expiry = (int) $m[1];
    $now = time();
    if ($expiry < $now || $expiry > $now + WB_RENDER_VT_TTL + 60) {
        return false;
    }
    $row = DB::one('SELECT status, lock_token FROM runs WHERE id = ?', [$runId]);
    if (!$row || $row['status'] !== 'verifying' || (string) ($row['lock_token'] ?? '') === '') {
        return false;
    }
    return hash_equals(wb_render_vt_sig($runId, (string) $row['lock_token'], $expiry), $m[2]);
}

/** True when the run has produced something that can be rendered (not queued / still generating). */
function wb_render_is_generated(array $run): bool
{
    if (in_array((string) ($run['status'] ?? ''), ['queued', 'generating'], true)) {
        return false;
    }
    return ($run['code'] ?? null) !== null || ($run['raw_output'] ?? null) !== null;
}

/** Scheme + host of the current request (the origin the verifier page loaded the frame from). */
function wb_render_request_origin(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^(?:[A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        return site_origin();
    }
    return (Http::isHttps() ? 'https' : 'http') . '://' . strtolower($host);
}

/**
 * Send the sandboxed document for a run.
 * $opts: verify (bool) inject the probe · mode 'station'|'playwright' · viewport (name) · light (bool,
 * secondary viewport: cheap checks only) · theme 'dark'|'light'|null · embed_anywhere (bool,
 * frame-ancestors *; alias: embed = 'anywhere') · parent_origin (string) · timeout_ms (int).
 */
function wb_render_document(array $run, array $task, array $opts = []): void
{
    $embedAnywhere = !empty($opts['embed_anywhere']) || (($opts['embed'] ?? null) === 'anywhere');
    Http::sandboxHeaders($embedAnywhere);
    $verify = !empty($opts['verify']);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        if ($verify) {
            header('Cache-Control: no-store');
        }
    }
    $type = (string) ($task['output_type'] ?? 'html');
    $code = isset($run['code']) && is_string($run['code']) && trim($run['code']) !== '' ? $run['code'] : null;
    $noOutput = $code === null || !in_array($type, WB_RENDER_LIVE_TYPES, true);
    $inject = $verify ? wb_render_probe_head($run, $task, $opts, $noOutput) : '';
    $theme = in_array($opts['theme'] ?? null, ['dark', 'light'], true) ? (string) $opts['theme'] : null;

    if ($type === 'html') {
        echo $code === null ? wb_render_empty_doc($task, $inject, $theme) : wb_render_inject_head($code, $inject);
        return;
    }
    if ($type === 'js') {
        echo $code === null
            ? wb_render_empty_doc($task, $inject, $theme)
            : wb_render_js_doc($run, $task, $code, $inject, $theme, $verify);
        return;
    }
    echo wb_render_text_doc($run, $task, $inject, $theme);
}

/**
 * Insert $inject as the very first content of <head>. Handles a BOM, leading comments, a doctype,
 * a missing <html> and a missing <head> (the HTML parser then creates the head implicitly).
 */
function wb_render_inject_head(string $html, string $inject): string
{
    if ($inject === '') {
        return $html;
    }
    if (str_starts_with($html, "\xEF\xBB\xBF")) {
        $html = substr($html, 3);
    }
    $attrs = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';
    $pos = wb_render_skip_trivia($html, 0);
    foreach (['<!doctype\b' . $attrs . '>', '<html\b' . $attrs . '>', '<head\b' . $attrs . '>'] as $i => $re) {
        if (preg_match('~\G' . $re . '~i', $html, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            if ($i === 2) {
                break;
            }
            $pos = wb_render_skip_trivia($html, $pos);
        }
    }
    return substr($html, 0, $pos) . $inject . substr($html, $pos);
}

/** Skip whitespace and HTML comments starting at $pos. */
function wb_render_skip_trivia(string $html, int $pos): int
{
    $len = strlen($html);
    while ($pos < $len) {
        if (ctype_space($html[$pos])) {
            $pos++;
            continue;
        }
        if (substr($html, $pos, 4) === '<!--') {
            $end = strpos($html, '-->', $pos + 4);
            if ($end === false) {
                return $pos;
            }
            $pos = $end + 3;
            continue;
        }
        break;
    }
    return $pos;
}

/** The task's checks definition (accepts the raw checks_json column or an already-decoded array). */
function wb_render_checks(array $task): array
{
    $raw = $task['checks_json'] ?? null;
    if (is_array($raw)) {
        return $raw;
    }
    return json_arr(is_string($raw) ? $raw : null);
}

/** Absolute URL of a static asset on the canonical origin (the frame itself has an opaque origin). */
function wb_render_asset(string $path): string
{
    return site_origin() . asset($path);
}

/** Probe configuration + scripts injected at the start of <head> in verify mode. */
function wb_render_probe_head(array $run, array $task, array $opts, bool $noOutput): string
{
    $type = (string) ($task['output_type'] ?? 'html');
    $checks = wb_render_checks($task);
    $browser = [];
    foreach ((array) ($checks['browser'] ?? []) as $c) {
        if (is_array($c) && isset($c['type']) && is_string($c['type'])) {
            $c['id'] = (string) ($c['id'] ?? $c['type']);
            $browser[] = $c;
        }
    }
    $tests = $type === 'js' && is_array($checks['tests'] ?? null) ? $checks['tests'] : null;
    $mode = ($opts['mode'] ?? 'station') === 'playwright' ? 'playwright' : 'station';
    $cfg = [
        'run_id' => (int) ($run['id'] ?? 0),
        'mode' => $mode,
        'viewport' => preg_match('/^[a-z0-9_-]{1,20}$/', (string) ($opts['viewport'] ?? '')) ? (string) $opts['viewport'] : 'desktop',
        'light' => !empty($opts['light']),
        'parentOrigin' => (string) ($opts['parent_origin'] ?? wb_render_request_origin()),
        'nonce' => random_token(12),
        'output_type' => $type,
        'no_output' => $noOutput,
        'checks' => $browser,
        'tests' => $tests,
        'timeout_ms' => (int) clamp((float) ($opts['timeout_ms'] ?? 15000), 5000, 120000),
        'load_timeout_ms' => 8000,
        'settle_ms' => 600,
    ];
    $tag = static fn(string $src): string => '<script data-wb-probe src="' . e(wb_render_asset($src)) . '"></script>';
    return '<script data-wb-probe>window.__WB_PROBE_CFG=' . js_json($cfg) . ';</script>'
        . '<script data-wb-probe>' . wb_render_prescript() . '</script>'
        . $tag('js/probe.js')
        . ($type === 'js' ? $tag('js/harness.js') : '')
        . ($mode === 'station' ? $tag('vendor/html2canvas.min.js') : '');
}

/**
 * Tiny pre-script (runs before the probe and any page code): WebGL contexts keep their drawing buffer
 * (so screenshots and pixel sampling see the scene) and requestAnimationFrame frame times are recorded.
 */
function wb_render_prescript(): string
{
    return '(function(w){var P=w.__WB_PRE={raf:[],ctx:[],rafOrig:null};'
        . 'try{var gc=HTMLCanvasElement.prototype.getContext;HTMLCanvasElement.prototype.getContext=function(t,a){'
        . 'var k=String(t).toLowerCase();if(k==="webgl"||k==="webgl2"||k==="experimental-webgl"){var o={};'
        . 'if(a&&typeof a==="object"){for(var p in a){o[p]=a[p];}}o.preserveDrawingBuffer=true;a=o;}'
        . 'var c=a===undefined?gc.call(this,t):gc.call(this,t,a);if(c&&P.ctx.length<64&&P.ctx.indexOf(this)<0){P.ctx.push(this);}return c;};}catch(e){}'
        . 'try{var r=w.requestAnimationFrame;if(r){P.rafOrig=r.bind(w);w.requestAnimationFrame=function(cb){'
        . 'if(typeof cb!=="function"){return r.call(w,cb);}return r.call(w,function(ts){var q=P.raf;'
        . 'if(q[q.length-1]!==ts){q.push(ts);if(q.length>4000){q.splice(0,2000);}}return cb(ts);});};}}catch(e){}'
        . '})(window);';
}

/** Shared <head> + neutral styles for documents we generate ourselves. */
function wb_render_shell(string $title, string $body, string $inject, ?string $theme, string $extraHead = ''): string
{
    $themeAttr = $theme !== null ? ' data-theme="' . e($theme) . '"' : '';
    return '<!doctype html><html lang="' . e(lang()) . '" dir="' . e(text_dir()) . '"' . $themeAttr . '><head>' . $inject
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . e($title) . '</title>'
        . '<style>' . wb_render_css() . '</style>' . $extraHead . '</head><body>' . $body . '</body></html>';
}

/** Neutral stylesheet (system fonts only: the sandbox CSP does not allow our self-hosted fonts). */
function wb_render_css(): string
{
    return ':root{color-scheme:dark;--bg:#07080c;--panel:#0f1117;--line:rgba(255,255,255,.09);--text:#ece6da;--muted:#9a978f;'
        . '--gold:#d9b26a;--green:#4fc28a;--red:#ef6b6b;--code:#c9c4b8;--kw:#d9b26a;--str:#8fd1a8;--num:#8fb8ff;--com:#6b6a66;}'
        . '@media (prefers-color-scheme:light){:root:not([data-theme=dark]){color-scheme:light;--bg:#f7f3ea;--panel:#fffdf8;--line:rgba(20,18,12,.1);'
        . '--text:#17140f;--muted:#6b665b;--gold:#9a7425;--green:#1f8a55;--red:#c43c3c;--code:#2b2720;--kw:#8a5d06;--str:#1d7a4a;--num:#2a5bb8;--com:#9a958a;}}'
        . ':root[data-theme=light]{color-scheme:light;--bg:#f7f3ea;--panel:#fffdf8;--line:rgba(20,18,12,.1);--text:#17140f;--muted:#6b665b;'
        . '--gold:#9a7425;--green:#1f8a55;--red:#c43c3c;--code:#2b2720;--kw:#8a5d06;--str:#1d7a4a;--num:#2a5bb8;--com:#9a958a;}'
        . '*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text)}'
        . 'body{font:15px/1.6 system-ui,-apple-system,"Segoe UI","Noto Sans Arabic","Geeza Pro",Tahoma,sans-serif;min-height:100vh}'
        . '.wb-wrap{max-width:1100px;margin:0 auto;padding:28px 20px 40px}'
        . '.wb-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px}'
        . '.wb-tag{font:600 11px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);'
        . 'border:1px solid var(--gold);border-radius:999px;padding:6px 10px}'
        . '.wb-title{font-size:17px;font-weight:600;margin:0;flex:1 1 240px}'
        . '.wb-btn{font-family:inherit;font-weight:600;font-size:14px;line-height:1;color:#14110a;background:linear-gradient(135deg,#f0d49a,#c99a4a);border:0;border-radius:10px;'
        . 'padding:11px 18px;cursor:pointer;box-shadow:0 6px 18px rgba(217,178,106,.25)}.wb-btn[disabled]{opacity:.6;cursor:progress}'
        . '.wb-panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:hidden}'
        . '.wb-panel+.wb-panel{margin-top:16px}.wb-panel-head{display:flex;justify-content:space-between;gap:12px;padding:12px 16px;'
        . 'border-bottom:1px solid var(--line);font-size:13px;color:var(--muted)}'
        . '.wb-code{margin:0;padding:14px 0;overflow:auto;font:13px/1.65 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--code);'
        . 'direction:ltr;text-align:left;tab-size:2;counter-reset:ln;max-height:none}'
        . '.wb-code .l{display:block;padding:0 16px 0 0;white-space:pre}.wb-code .l::before{counter-increment:ln;content:counter(ln);display:inline-block;'
        . 'width:3.2em;padding-right:1.1em;text-align:right;color:var(--com);user-select:none}'
        . '.wb-code .k{color:var(--kw)}.wb-code .s{color:var(--str)}.wb-code .n{color:var(--num)}.wb-code .c{color:var(--com);font-style:italic}'
        . '.wb-raw{margin:0;padding:16px;white-space:pre-wrap;word-break:break-word;font:13.5px/1.7 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;'
        . 'unicode-bidi:plaintext}'
        . '.wb-empty{min-height:100vh;display:grid;place-items:center;text-align:center;padding:24px}'
        . '.wb-empty-card{max-width:420px}.wb-empty-icon{width:56px;height:56px;margin:0 auto 14px;border-radius:50%;border:1px solid var(--line);'
        . 'display:grid;place-items:center;color:var(--muted);font-size:22px}.wb-empty h1{font-size:18px;margin:0 0 6px}'
        . '.wb-empty p{margin:0;color:var(--muted);font-size:14px}'
        . '.wb-results table{width:100%;border-collapse:collapse;font-size:13px}.wb-results th,.wb-results td{padding:9px 14px;'
        . 'border-bottom:1px solid var(--line);text-align:start;vertical-align:top}.wb-results th{font-weight:600;color:var(--muted);font-size:12px}'
        . '.wb-results td.ms{font-family:ui-monospace,Menlo,Consolas,monospace;white-space:nowrap;color:var(--muted)}'
        . '.wb-results code{font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;word-break:break-all;direction:ltr;unicode-bidi:isolate}'
        . '.wb-ok{color:var(--green);font-weight:700}.wb-bad{color:var(--red);font-weight:700}'
        . '.wb-sum{font-weight:600;color:var(--text)}.wb-sum.ok{color:var(--green)}.wb-sum.bad{color:var(--red)}'
        . '.wb-note{color:var(--muted);font-size:13px;margin:12px 2px 0}';
}

/** Neutral "No renderable output" document. */
function wb_render_empty_doc(array $task, string $inject, ?string $theme): string
{
    $body = '<main class="wb-empty"><div class="wb-empty-card"><div class="wb-empty-icon" aria-hidden="true">&#8709;</div>'
        . '<h1>' . _e('verifier.render.no_output') . '</h1><p>' . _e('verifier.render.no_output_hint') . '</p></div></main>';
    return wb_render_shell(__('verifier.render.no_output'), $body, $inject, $theme);
}

/** Escaped raw output for answer / agent / text runs. */
function wb_render_text_doc(array $run, array $task, string $inject, ?string $theme): string
{
    $raw = $run['raw_output'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        $raw = is_string($run['code'] ?? null) ? (string) $run['code'] : '';
    }
    if (trim($raw) === '') {
        return wb_render_empty_doc($task, $inject, $theme);
    }
    $title = L($task, 'title');
    $body = '<div class="wb-wrap"><div class="wb-bar"><span class="wb-tag">' . e(strtoupper((string) ($task['output_type'] ?? 'text'))) . '</span>'
        . '<h1 class="wb-title">' . e($title) . '</h1></div><section class="wb-panel"><div class="wb-panel-head"><span>'
        . _e('verifier.render.raw_output') . '</span><span>' . e(fmt_num(mb_strlen($raw))) . ' ' . _e('verifier.render.chars') . '</span></div>'
        . '<pre class="wb-raw" dir="auto">' . e($raw) . '</pre></section></div>';
    return wb_render_shell($title !== '' ? $title : __('verifier.render.raw_output'), $body, $inject, $theme);
}

/** Harness page for js outputs: highlighted code + "Run tests" (public tasks) or automatic tests (verify mode). */
function wb_render_js_doc(array $run, array $task, string $code, string $inject, ?string $theme, bool $verify): string
{
    $checks = wb_render_checks($task);
    $tests = is_array($checks['tests'] ?? null) ? $checks['tests'] : null;
    $showTests = $tests !== null && !empty($tests['cases']) && ($verify || ($task['visibility'] ?? 'public') === 'public');
    $title = L($task, 'title');
    $lines = substr_count(rtrim($code, "\r\n"), "\n") + 1;
    $data = [
        'code' => $code,
        'tests' => $showTests ? $tests : null,
        'auto' => $verify,
        'i18n' => [
            'run' => __('verifier.render.run_tests'),
            'running' => __('verifier.render.running'),
            'passed' => __('verifier.render.passed_n'),
            'case' => __('verifier.render.case'),
            'time' => __('verifier.render.time'),
            'result' => __('verifier.render.result'),
            'pass' => __('verifier.render.pass'),
            'fail' => __('verifier.render.fail'),
            'no_worker' => __('verifier.render.no_worker'),
        ],
    ];
    $button = $showTests && !$verify
        ? '<button type="button" class="wb-btn" id="wb-run">' . _e('verifier.render.run_tests') . '</button>' : '';
    $note = $tests !== null && !$showTests ? '<p class="wb-note">' . _e('verifier.render.tests_private') . '</p>' : '';
    $body = '<div class="wb-wrap"><div class="wb-bar"><span class="wb-tag">JS</span><h1 class="wb-title">' . e($title) . '</h1>' . $button . '</div>'
        . '<section class="wb-panel wb-results" id="wb-results" hidden aria-live="polite"></section>'
        . '<section class="wb-panel"><div class="wb-panel-head"><span>' . _e('verifier.render.solution') . '</span><span>'
        . e(fmt_num($lines)) . ' ' . _e('verifier.render.lines') . '</span></div>'
        . '<pre class="wb-code" id="wb-code" dir="ltr"><code>' . e($code) . '</code></pre></section>' . $note . '</div>'
        . '<script type="application/json" id="wb-harness-data">' . js_json($data) . '</script>'
        . ($verify ? '' : '<script src="' . e(wb_render_asset('js/harness.js')) . '"></script>');
    return wb_render_shell($title !== '' ? $title : 'JS', $body, $inject, $theme);
}
