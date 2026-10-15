// DEV ONLY — builds sql/seed/tasks/secure.json.   node tools/dev/references/build/secure.mjs
import { finalizeCases, jsContract, STATIC_PARSES, STATIC_NO_EVAL, STATIC_NO_FUNCTION, STATIC_NO_INNERHTML, R, writeTrack, LCG } from './common.mjs';

const JS = (v) => JSON.stringify(v);
const RUBRIC = [R.security(4), R.robustness(3), R.readability(2), R.idiomatic(1)];
const STATIC = [STATIC_PARSES, STATIC_NO_EVAL, STATIC_NO_FUNCTION];

// ════════════════════════════════════════════ 1. context encoder ═════════════════════════════════
// Test-side decoders (no eval/Function — the verifier's worker may run under a strict CSP).
const ENC_KIT = `const wellFormed = (s) => s.replace(/[\\uD800-\\uDBFF](?![\\uDC00-\\uDFFF])|(?<![\\uD800-\\uDBFF])[\\uDC00-\\uDFFF]/g, '\\uFFFD');
const decodeHtml = (s) => { let out = ''; for (let i = 0; i < s.length; i++) { const c = s[i]; if (c === '<' || c === '>') return null; if (c !== '&') { out += c; continue; } const m = /^&(amp|lt|gt|quot|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});/.exec(s.slice(i, i + 12)); if (!m) return null; const r = m[1]; if (r === 'amp') out += '&'; else if (r === 'lt') out += '<'; else if (r === 'gt') out += '>'; else if (r === 'quot') out += '"'; else { const cp = r[1] === 'x' || r[1] === 'X' ? parseInt(r.slice(2), 16) : parseInt(r.slice(1), 10); if (cp === 0 || cp > 0x10FFFF || (cp >= 0xD800 && cp <= 0xDFFF) || (cp >= 0x80 && cp <= 0x9F)) return null; out += String.fromCodePoint(cp); } i += m[0].length - 1; } return out; };
const decodeJs = (s, q) => { let out = ''; for (let i = 0; i < s.length; i++) { const c = s[i]; if (c === q || c === '\\n' || c === '\\r') return null; if (c !== '\\\\') { out += c; continue; } const n = s[++i]; if (n === undefined) return null; if (n === 'x') { const h = s.slice(i + 1, i + 3); if (!/^[0-9a-fA-F]{2}$/.test(h)) return null; out += String.fromCharCode(parseInt(h, 16)); i += 2; } else if (n === 'u') { if (s[i + 1] === '{') { const e = s.indexOf('}', i); const h = e < 0 ? '' : s.slice(i + 2, e); if (!/^[0-9a-fA-F]{1,6}$/.test(h) || parseInt(h, 16) > 0x10FFFF) return null; out += String.fromCodePoint(parseInt(h, 16)); i = e; } else { const h = s.slice(i + 1, i + 5); if (!/^[0-9a-fA-F]{4}$/.test(h)) return null; out += String.fromCharCode(parseInt(h, 16)); i += 4; } } else if (n === '0' && !/[0-9]/.test(s[i + 1] || '')) out += '\\0'; else if (/[1-9]/.test(n)) return null; else out += ({ n: '\\n', r: '\\r', t: '\\t', b: '\\b', f: '\\f', v: '\\v' })[n] ?? n; } return out; };
const check = (ctx, input, out) => { if (typeof out !== 'string') return 'not a string'; const want = wellFormed(String(input ?? '')); if (ctx === 'html' || ctx === 'attr') { if (ctx === 'attr' && /["'\`]/.test(out)) return 'raw quote'; const d = decodeHtml(out); if (d === null) return 'raw < > or bad reference'; return d === want ? 'ok' : 'decodes to ' + JSON.stringify(d).slice(0, 60); } if (ctx === 'js') { if (!/^[\\x20-\\x7E]*$/.test(out)) return 'non-printable or non-ASCII output'; if (/["'\`<>&]/.test(out)) return 'raw dangerous character'; const a = decodeJs(out, '"'), b = decodeJs(out, "'"); if (a === null || b === null) return 'not a valid strict-mode string literal body'; return a === want && b === want ? 'ok' : 'decodes to ' + JSON.stringify(a).slice(0, 60); } return 'unknown ctx'; };
const sweep = (ctx, inputs) => inputs.map((x, i) => { let out; try { out = encodeFor(ctx, x); } catch (e) { return i + ': threw ' + (e && e.name); } const r = check(ctx, x, out); return r === 'ok' ? null : i + ': ' + r; }).filter(Boolean);`;
const encCase = (name, ctx, inputs) => ({ name, script: `${ENC_KIT}\nreturn sweep(${JS(ctx)}, ${JS(inputs)});`, expect: [] });

const XSS = ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', "' onmouseover='alert(1)", '`onfocus=alert(1) autofocus`', '<svg/onload=alert(1)>', '&lt;b&gt; already escaped', '&amp;&amp;&#x3C;', 'Tom & Jerry <3 > 2', '<!-- comment -->', ']]><![CDATA[', 'a\tb\nc', 'مرحبا <b>بالعالم</b>', '😀 & 🎉', '', ' ', '&', '&#', '&#x;', '<<>>&&""\'\'``'];
const JS_PAYLOADS = ["';alert(1);//", '";alert(1);//', '\\";alert(1);//', "\\';alert(1);//", '</script><script>alert(1)</script>', '</SCRIPT >', '<!--<script>', '\u2028\u2029', 'line1\nline2\r\nline3', '`${alert(1)}`', '\\', '\\\\x41', '\\u0041', 'emoji 😀 and عربي', '\u0000\u0007\u007f\u0085', ']]>-->', '\t tab', 'a"b\'c`d', '\u00a0nbsp', '9\\8', '&amp;'];

async function encoderTask() {
  const slug = 'secure-context-encoder';
  const errs = (calls) => `return ${calls}.map(([c, v]) => { try { encodeFor(c, v); return 'ok'; } catch (e) { return e instanceof TypeError ? 'TypeError' : (e && e.name) || 'error'; } });`;
  const cases = [
    { name: 'url_basic_reserved', args: ['url', 'a b&c=d/e?f#g+h'], expect: 'a%20b%26c%3Dd%2Fe%3Ff%23g%2Bh' },
    { name: 'url_rfc3986_marks_encoded', args: ['url', "!'()*"], expect: '%21%27%28%29%2A' },
    { name: 'url_unreserved_kept', args: ['url', 'AZaz09-._~'], expect: 'AZaz09-._~' },
    { name: 'url_utf8_bytes_uppercase_hex', args: ['url', 'é東😀'], expect: '%C3%A9%E6%9D%B1%F0%9F%98%80' },
    { name: 'url_lone_surrogate_replaced', script: "return encodeFor('url', 'a\\uD800b\\uDFFF');", expect: 'a%EF%BF%BDb%EF%BF%BD' },
    { name: 'url_javascript_scheme_inert', args: ['url', 'javascript:alert(1)'], expect: 'javascript%3Aalert%281%29' },
    { name: 'url_control_and_percent', args: ['url', '100%\n\t\u0000'], expect: '100%25%0A%09%00' },
    { name: 'url_number_value', args: ['url', 3.5], expect: '3.5' },
    encCase('html_xss_payloads', 'html', XSS),
    encCase('html_unicode_roundtrip', 'html', ['Ünïcödé façade', 'عربي — RTL ‏mark', '東京 タワー', '🇸🇮🏳️‍🌈👩‍💻', '\u200b\u200d\ufeff']),
    encCase('html_lone_surrogates', 'html', ['\uDC00x\uD800', '\uD83D', 'ok\uDE00']),
    encCase('attr_breakout_payloads', 'attr', XSS),
    encCase('attr_quote_styles', 'attr', ['"', "'", '`', '" autofocus onfocus="x', "' style='x", 'a=b c=d']),
    encCase('js_breakout_payloads', 'js', JS_PAYLOADS),
    encCase('js_unicode_and_controls', 'js', ['é', '東京', '😀', '\uD800 lone', '\u0001\u001f', 'ASCII only 123', '¡¿«»']),
    encCase('js_backslash_sequences', 'js', ['\\', '\\\\', '\\n', '\\x3c', '\\u{1F600}', 'C:\\temp\\new']),
    { name: 'html_double_encoding_kept_visible', script: `${ENC_KIT}\nconst out = encodeFor('html', '&lt;b&gt;'); return decodeHtml(out);`, expect: '&lt;b&gt;' },
    { name: 'value_type_conversion', script: `return [encodeFor('html', 42), encodeFor('attr', true), encodeFor('url', 10n), encodeFor('js', null), encodeFor('html', undefined), encodeFor('url', -0.5)];`, expect: ['42', 'true', '10', '', '', '-0.5'] },
    { name: 'non_text_values_throw_type_error', script: errs(`[['html', {}], ['attr', []], ['js', () => 1], ['url', Symbol('x')], ['html', new String('x')], ['html', { toString() { return '<b>'; } }]]`), expect: Array(6).fill('TypeError') },
    { name: 'unknown_context_throws_type_error', script: errs(`[['HTML', 'x'], ['css', 'x'], ['', 'x'], [null, 'x'], [undefined, 'x'], ['attribute', 'x'], ['url ', 'x'], [{ toString() { return 'html'; } }, 'x']]`), expect: Array(8).fill('TypeError') },
    { name: 'random_strings_all_contexts', script: `${ENC_KIT}\n${LCG(71)} const pool = ['<', '>', '&', '"', "'", '\`', '\\\\', '/', '=', ' ', '\\n', '\\u2028', 'a', 'Z', '9', 'é', '東', '😀', '\\uD800', '\\uDC00', '%', '#', ';', '\\t']; const bad = []; for (let k = 0; k < 400; k++) { let s = ''; const n = ri(0, 24); for (let i = 0; i < n; i++) s += pool[ri(0, pool.length - 1)]; for (const ctx of ['html', 'attr', 'js']) { const r = check(ctx, s, encodeFor(ctx, s)); if (r !== 'ok') bad.push(ctx + ' ' + JSON.stringify(s) + ' ' + r); } const u = encodeFor('url', s); if (!/^(?:[A-Za-z0-9\\-._~]|%[0-9A-F]{2})*$/.test(u) || decodeURIComponent(u) !== wellFormed(s)) bad.push('url ' + JSON.stringify(s)); } return bad.slice(0, 5);`, expect: [] },
    { name: 'large_input_linear_time', script: `${ENC_KIT}\nconst s = '<a href="x">Tom & Jerry 😀</a>\\n'.repeat(8000); const outs = ['html', 'attr', 'js', 'url'].map((c) => encodeFor(c, s)); return [check('html', s, outs[0]), check('attr', s, outs[1]), check('js', s, outs[2]), decodeURIComponent(outs[3]) === s, outs.every((o) => o.length <= s.length * 12)];`, expect: ['ok', 'ok', 'ok', true, true] },
  ];
  return {
    slug, track: 'secure',
    title_en: 'Context-Aware Output Encoder', title_ar: 'مُرمِّز مخرجات واعٍ بالسياق',
    summary_en: 'Encode untrusted text for HTML text, quoted attributes, JavaScript string literals and URL query components — verified against XSS breakout payloads.',
    summary_ar: 'ترميز النصوص غير الموثوقة لسياقات نص HTML والسمات المقتبسة وسلاسل جافاسكربت ومكوّنات استعلام الروابط، مع التحقق عبر حمولات اختراق XSS.',
    prompt: `# Context-Aware Output Encoder

A server-side rendering layer inserts untrusted strings (user names, search terms, comments) into HTML pages. One generic "escape" function is not enough: every insertion point needs its own encoding. Implement \`function encodeFor(context, value)\`.

## Contexts
| context | where the result is inserted | requirements on the returned string |
|---|---|---|
| \`"html"\` | element text content: \`<p>HERE</p>\` | contains no \`<\` or \`>\`; every \`&\` starts a character reference; decoding the references gives back exactly the input |
| \`"attr"\` | an attribute value quoted with either \`"\` or \`'\`: \`<input value="HERE">\` | everything required for \`"html"\`, and additionally no raw \`"\`, \`'\` or \`\` \` \`\` |
| \`"js"\` | the inside of a quoted JavaScript string literal (\`"…"\` or \`'…'\`) in an inline \`<script>\` block | only printable ASCII (U+0020–U+007E); no raw \`"\`, \`'\`, \`\` \` \`\`, \`<\`, \`>\` or \`&\`; a backslash only as part of an escape sequence; read as the body of a strict-mode string literal (with either quote style) it yields exactly the input |
| \`"url"\` | one query-string component: \`?q=HERE\` | exact format below |

- Character references allowed for \`"html"\` / \`"attr"\`: \`&amp;\` \`&lt;\` \`&gt;\` \`&quot;\` and numeric references \`&#NNN;\` / \`&#xHHH;\` (for example \`&#39;\` for \`'\`). Other characters, including non-ASCII letters and emoji, may stay as they are.
- \`"url"\`: take the UTF-8 bytes of the input; keep \`A–Z a–z 0–9 - . _ ~\`; write every other byte as \`%XX\` with uppercase hex digits (space → \`%20\`, \`'\` → \`%27\`, \`é\` → \`%C3%A9\`).

## Values
- Strings are used as they are; numbers, booleans and bigints are converted with \`String(value)\`; \`null\` and \`undefined\` become \`""\`.
- Any other type (objects — including \`String\` wrapper objects — arrays, functions, symbols) → throw a \`TypeError\`.
- Lone UTF-16 surrogates (malformed text) are replaced by U+FFFD before encoding, in every context.
- An unknown context (anything except the four lowercase strings above) → throw a \`TypeError\`. Never fall back to a default context.

## Constraints
The code runs in a Web Worker (no DOM). Do not use \`eval\`, \`Function\` or \`innerHTML\`. Inputs can be about 1 MB; encoding must take linear time.

## Examples
\`\`\`js
encodeFor("url", "a b&c");            // → "a%20b%26c"
encodeFor("html", "<b>Tom & Jerry</b>"); // e.g. "&lt;b&gt;Tom &amp; Jerry&lt;/b&gt;"
encodeFor("js", "</script>");          // e.g. "\\\\x3C\\\\x2Fscript\\\\x3E"
encodeFor("css", "x");                 // throws TypeError
\`\`\`

${jsContract('encodeFor')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [...STATIC, STATIC_NO_INNERHTML], tests: { entry: 'encodeFor', timeout_ms: 3000, weight: 10, cases: await finalizeCases(slug, 'encodeFor', cases) } },
  };
}

// ════════════════════════════════════════════ 2. redirect guard ══════════════════════════════════
async function redirectTask() {
  const slug = 'secure-redirect-guard';
  const O = 'https://app.example.test';
  const H = ['example.test', 'partner.example.org'];
  const c = (name, target, expect, origin = O, hosts = H) => ({ name, args: [target, origin, hosts], expect });
  const many = (name, targets, expect) => ({ name, script: `const O = ${JS(O)}, H = ${JS(H)}; return ${JS(targets)}.map((t) => safeRedirect(t, O, H));`, expect });
  const cases = [
    c('relative_path', '/dashboard', '/dashboard'),
    c('path_query_fragment_normalised', '/search?q=a b#top results', '/search?q=a%20b#top%20results'),
    c('relative_without_slash', 'settings/profile', '/settings/profile'),
    c('query_only', '?tab=2', '/?tab=2'),
    c('fragment_only', '#billing', '/#billing'),
    c('empty_target_is_home', '', '/'),
    c('absolute_same_origin_made_relative', 'https://app.example.test/x?y=1', '/x?y=1'),
    c('same_origin_default_port', 'https://APP.example.test:443/ok', '/ok'),
    c('dot_segments_normalised', '/a/b/../../etc/passwd', '/etc/passwd'),
    c('allowed_subdomain', 'https://docs.example.test/guide', 'https://docs.example.test/guide'),
    c('allowed_exact_host_normalised', 'HTTPS://EXAMPLE.test', 'https://example.test/'),
    c('allowed_partner_with_path', 'https://partner.example.org/cb?code=1', 'https://partner.example.org/cb?code=1'),
    c('allowed_deep_subdomain', 'https://a.b.partner.example.org/', 'https://a.b.partner.example.org/'),
    many('protocol_relative_and_backslash_tricks', ['//evil.test/login', '/\\evil.test', '\\\\evil.test', '\\/evil.test', '/\t/evil.test', ' //evil.test', '///evil.test', 'https:///evil.test'], Array(8).fill('/')),
    many('dangerous_schemes', ['javascript:alert(document.domain)', 'JaVaScRiPt:alert(1)', ' \tjavascript:alert(1)', 'java\nscript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)', 'file:///etc/passwd', 'blob:https://app.example.test/1234'], Array(8).fill('/')),
    many('lookalike_hosts', ['https://app.example.test.evil.test/', 'https://evilexample.test/', 'https://example.test.evil.test/', 'https://partner.example.org.evil.test/', 'https://xn--partner-xyz.example.org.evil/', 'https://docs.example.test./x'], Array(6).fill('/')),
    many('credentials_and_at_sign_tricks', ['https://app.example.test@evil.test/', 'https://user:pw@app.example.test/', 'https://evil.test#@app.example.test', 'https://evil.test\\@app.example.test/', 'https://docs.example.test@evil.test', 'https://u@docs.example.test/'], Array(6).fill('/')),
    many('scheme_and_port_rules', ['http://docs.example.test/', 'http://app.example.test/x', 'https://docs.example.test:8443/', 'ftp://example.test/', 'wss://docs.example.test/'], Array(5).fill('/')),
    c('default_https_port_allowed', 'https://docs.example.test:443/a', 'https://docs.example.test/a'),
    c('same_origin_double_slash_path_returned_absolute', 'https://app.example.test//evil.test/x', 'https://app.example.test//evil.test/x'),
    c('encoded_slashes_stay_relative', '/%2F%2Fevil.test', '/%2F%2Fevil.test'),
    c('scheme_relative_special_url', 'https:evil.test', '/evil.test'),
    c('encoded_tab_is_just_a_path', '/%09/evil.test', '/%09/evil.test'),
    many('unicode_hosts', ['https://ＥＶＩＬ.test/', 'https://pаrtner.example.org/', 'https://evil。test/', 'https://docs.example.test/ünïcode path'], ['/', '/', '/', 'https://docs.example.test/%C3%BCn%C3%AFcode%20path']),
    many('ip_literal_hosts', ['https://1.2.3.4/', 'https://0x7f.1/', 'https://[::1]/', 'https://127.0.0.1:443/'], Array(4).fill('/')),
    { name: 'non_string_and_oversized_targets', script: `const O = ${JS(O)}, H = ${JS(H)}; return [null, undefined, 42, { toString() { return '/x'; } }, ['/a'], '/' + 'a'.repeat(2047), '/' + 'a'.repeat(2048)].map((t) => { const r = safeRedirect(t, O, H); return typeof r === 'string' ? r.length : 'not a string'; });`, expect: [1, 1, 1, 1, 1, 2048, 1] },
    c('other_app_origin_and_empty_allow_list', 'https://docs.example.test/', '/', 'https://shop.example.org', []),
    c('other_app_origin_same_origin_ok', '/cart?id=7', '/cart?id=7', 'https://shop.example.org', []),
    c('http_dev_origin_with_port', 'http://localhost:8080/admin', '/admin', 'http://localhost:8080', ['example.test']),
    c('http_dev_origin_other_port_refused', 'http://localhost:9999/admin', '/', 'http://localhost:8080', ['example.test']),
  ];
  return {
    slug, track: 'secure',
    title_en: 'Open-Redirect Guard', title_ar: 'حارس إعادة التوجيه المفتوحة',
    summary_en: 'Validate post-login redirect targets the way the browser parses them — defeating protocol-relative, backslash, credential, look-alike host and scheme tricks.',
    summary_ar: 'التحقق من وجهات إعادة التوجيه بعد تسجيل الدخول كما يحللها المتصفح، وإحباط حيل الروابط بلا مخطط والشرطة المائلة العكسية وبيانات الاعتماد والنطاقات المشابهة والمخططات الخطرة.',
    prompt: `# Open-Redirect Guard

After login the app redirects to the value of \`?next=…\`. Attackers abuse such parameters to send users to phishing pages that look like the app. Implement \`function safeRedirect(target, appOrigin, allowedHosts)\`, which returns the location the app may redirect to.

## Inputs
- \`target\` — the untrusted value of the \`next\` parameter (already URL-decoded once by the framework). It may be of any type.
- \`appOrigin\` — the app's own origin, normalised: scheme, lowercase host and optional non-default port, no trailing slash (e.g. \`"https://app.example.test"\`).
- \`allowedHosts\` — array of lowercase ASCII hostnames we trust (e.g. \`["example.test", "partner.example.org"]\`).

## Policy
Interpret \`target\` exactly as a browser would when it follows the redirect from a page at \`appOrigin + "/"\` — i.e. with the WHATWG URL rules (the standard \`URL\` class is available). Then:
1. Refuse targets that are not strings, are longer than 2048 characters, or cannot be parsed.
2. Refuse any URL that carries a username or password.
3. A URL with the same origin as \`appOrigin\` is allowed. Return it in relative form — path + query + fragment exactly as serialised by the URL parser (e.g. \`"/settings?tab=2#top"\`) — **unless** that relative form starts with \`//\` (a browser would read it as a link to another host); then return the full absolute URL instead.
4. Any other URL is allowed only if its scheme is \`https:\`, it has no explicit port after normalisation (\`:443\` counts as none), and its hostname is exactly one of \`allowedHosts\` or a subdomain of one (\`docs.example.test\` matches \`example.test\`; \`evilexample.test\` does not). Return its full absolute serialisation (\`url.href\`).
5. Every refused case returns \`"/"\`.

## Examples
\`\`\`js
const O = "https://app.example.test", H = ["example.test", "partner.example.org"];
safeRedirect("/dashboard", O, H);                       // → "/dashboard"
safeRedirect("https://docs.example.test/guide", O, H);  // → "https://docs.example.test/guide"
safeRedirect("//evil.test/login", O, H);                // → "/"
safeRedirect("javascript:alert(document.domain)", O, H); // → "/"
\`\`\`

## Constraints
Do not use \`eval\` or \`Function\`. Decide on the parsed URL, not on regular expressions over the raw string.

${jsContract('safeRedirect')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: STATIC, tests: { entry: 'safeRedirect', timeout_ms: 2000, weight: 10, cases: await finalizeCases(slug, 'safeRedirect', cases) } },
  };
}

// ════════════════════════════════════════════ 3. vfs resolve ═════════════════════════════════════
const FS = {
  '/': { type: 'dir' }, '/srv': { type: 'dir' }, '/srv/secret.txt': { type: 'file' },
  '/srv/public': { type: 'dir' }, '/srv/public/index.html': { type: 'file' }, '/srv/public/A.txt': { type: 'file' }, '/srv/public/%41.txt': { type: 'file' }, '/srv/public/✓.txt': { type: 'file' }, '/srv/public/a+b.txt': { type: 'file' },
  '/srv/public/docs': { type: 'dir' }, '/srv/public/docs/guide.md': { type: 'file' }, '/srv/public/docs/a b.txt': { type: 'file' }, '/srv/public/docs/back': { type: 'link', target: '../index.html' },
  '/srv/public/docs/sub': { type: 'dir' }, '/srv/public/docs/sub/deep.txt': { type: 'file' },
  '/srv/public/.env': { type: 'file' }, '/srv/public/.git': { type: 'dir' }, '/srv/public/.git/config': { type: 'file' }, '/srv/public/cfg': { type: 'link', target: '.git/config' },
  '/srv/public/releases': { type: 'dir' }, '/srv/public/releases/v2': { type: 'dir' }, '/srv/public/releases/v2/notes.txt': { type: 'file' },
  '/srv/public/latest': { type: 'link', target: 'releases/v2' }, '/srv/public/current.txt': { type: 'link', target: 'latest/notes.txt' },
  '/srv/public/shared': { type: 'link', target: '/srv/shared' }, '/srv/shared': { type: 'dir' }, '/srv/shared/x.txt': { type: 'file' },
  '/srv/public/up': { type: 'link', target: '../private/key.pem' }, '/srv/private': { type: 'dir' }, '/srv/private/key.pem': { type: 'file' },
  '/srv/public/old': { type: 'link', target: '/srv/public-old/data.txt' }, '/srv/public-old': { type: 'dir' }, '/srv/public-old/data.txt': { type: 'file' },
  '/srv/public/loop': { type: 'link', target: 'loop' }, '/srv/public/ping': { type: 'link', target: 'pong' }, '/srv/public/pong': { type: 'link', target: './ping' },
  '/srv/public/abs-inside': { type: 'link', target: '/srv/public/docs/guide.md' }, '/srv/public/via-outside': { type: 'link', target: '/srv/shared/../public/index.html' },
  '/srv/public/dirlink': { type: 'link', target: 'docs' }, '/srv/public/broken': { type: 'link', target: 'nowhere.txt' }, '/srv/public/dotdot-root': { type: 'link', target: '../../../../srv/public/A.txt' },
};
for (let i = 1; i <= 41; i++) FS['/srv/public/h' + i] = { type: 'link', target: i < 41 ? 'h' + (i + 1) : 'index.html' };
const vfs = (name, requests, expect, root = '/srv/public') => ({ name, script: `const FS = ${JS(FS)}; return ${JS(requests)}.map((p) => resolveSafe(FS, ${JS(root)}, p));`, expect });

async function vfsTask() {
  const slug = 'secure-vfs-resolve';
  const P = (p) => '/srv/public/' + p;
  const cases = [
    vfs('plain_files', ['index.html', 'docs/guide.md', 'docs/sub/deep.txt'], [P('index.html'), P('docs/guide.md'), P('docs/sub/deep.txt')]),
    vfs('lexical_normalisation', ['docs//guide.md', './docs/./guide.md', 'docs/sub/../guide.md', '/index.html', 'docs/sub/./../sub/deep.txt'], [P('docs/guide.md'), P('docs/guide.md'), P('docs/guide.md'), P('index.html'), P('docs/sub/deep.txt')]),
    vfs('percent_decoding_once', ['docs/a%20b.txt', '%E2%9C%93.txt', 'docs%2Fguide.md', 'a+b.txt', '%2541.txt'], [P('docs/a b.txt'), P('✓.txt'), P('docs/guide.md'), P('a+b.txt'), P('%41.txt')]),
    // Every refusal scenario ends with a benign near-miss control that must still resolve, so an implementation
    // that refuses everything cannot pass it (a guard that blocks all requests is broken, not secure).
    vfs('dot_dot_escapes_refused', ['../secret.txt', 'docs/../../secret.txt', '..', 'docs/../..', 'docs/sub/../../../srv/secret.txt', '../index.html', 'docs/../../docs/guide.md', '../../public/index.html', 'docs/../index.html'], [null, null, null, null, null, null, null, null, P('index.html')]),
    vfs('encoded_traversal_refused', ['%2e%2e/secret.txt', '%2E%2E%2Fsecret.txt', '..%2fsecret.txt', 'docs%2f..%2f..%2fsecret.txt', '.%2e/secret.txt', 'docs%2f..%2findex.html'], [null, null, null, null, null, P('index.html')]),
    vfs('double_encoding_not_decoded_twice', ['%252e%252e/secret.txt', '%252e%252e%252fsecret.txt', '%2541.txt'], [null, null, P('%41.txt')]),
    vfs('malformed_escapes_refused', ['%', '%zz', 'docs/%2', '%c0%ae%c0%ae/secret.txt', '%ff.txt', '%ed%a0%80.txt', '%41.txt'], [null, null, null, null, null, null, P('A.txt')]),
    vfs('control_chars_and_backslashes_refused', ['index.html%00.png', 'index.html%0a', 'docs\\..\\..\\secret.txt', 'docs%5c..%5c..%5csecret.txt', 'index.html%7f', 'index.html\u0000', 'docs/a%20b.txt'], [null, null, null, null, null, null, P('docs/a b.txt')]),
    vfs('hidden_segments_refused', ['.env', '%2eenv', 'docs/../.env', '.git/config', 'cfg', './.git/config', './docs/./guide.md'], [null, null, null, null, null, null, P('docs/guide.md')]),
    vfs('directories_and_missing_entries', ['', '/', 'docs', 'docs/', 'missing.txt', 'index.html/x', 'broken', 'docs/sub/deep.txt'], [null, null, null, null, null, null, null, P('docs/sub/deep.txt')]),
    vfs('links_inside_root_followed', ['latest/notes.txt', 'current.txt', 'docs/back', 'abs-inside', 'dirlink/sub/deep.txt'], [P('releases/v2/notes.txt'), P('releases/v2/notes.txt'), P('index.html'), P('docs/guide.md'), P('docs/sub/deep.txt')]),
    vfs('links_escaping_root_refused', ['shared/x.txt', 'up', 'old', 'shared', 'latest/notes.txt'], [null, null, null, null, P('releases/v2/notes.txt')]),
    vfs('prefix_confusion_refused', ['old', '../public-old/data.txt', 'A.txt'], [null, null, P('A.txt')]),
    vfs('link_detours_resolved_physically', ['via-outside', 'dotdot-root'], [P('index.html'), P('A.txt')]),
    vfs('link_loops_refused', ['loop', 'ping', 'pong/x', 'current.txt'], [null, null, null, P('releases/v2/notes.txt')]),
    vfs('hop_limit_40', ['h2', 'h1'], [P('index.html'), null]),
    vfs('lexical_dotdot_before_links', ['latest/../index.html', 'dirlink/../index.html', 'latest/..'], [P('index.html'), P('index.html'), null]),
    vfs('nested_root', ['back', 'guide.md', 'sub/deep.txt', '../index.html'], [null, '/srv/public/docs/guide.md', '/srv/public/docs/sub/deep.txt', null], '/srv/public/docs'),
    vfs('filesystem_root_as_root', ['srv/secret.txt', 'srv/public/up', 'srv/public/.env'], ['/srv/secret.txt', '/srv/private/key.pem', null], '/'),
    { name: 'non_string_and_oversized_requests', script: `const FS = ${JS(FS)}; return [null, undefined, 42, ['index.html'], { toString() { return 'index.html'; } }, 'a/'.repeat(2047) + 'index.html', '../'.repeat(1400) + 'x', 'index.html'].map((p) => resolveSafe(FS, '/srv/public', p));`, expect: [null, null, null, null, null, null, null, P('index.html')] },
    { name: 'long_valid_request', script: `const FS = ${JS(FS)}; return resolveSafe(FS, '/srv/public', './'.repeat(2040) + 'index.html');`, expect: P('index.html') },
    { name: 'fs_is_not_modified', script: `const FS = ${JS(FS)}; const before = JSON.stringify(FS); ['latest/notes.txt', 'loop', '../x', 'h1'].forEach((p) => resolveSafe(FS, '/srv/public', p)); return JSON.stringify(FS) === before;`, expect: true },
    { name: 'prototype_keys_are_not_entries', script: `const FS = { '/': { type: 'dir' }, '/r': { type: 'dir' }, '/r/ok.txt': { type: 'file' } }; return ['__proto__', 'constructor', 'toString', 'hasOwnProperty', 'ok.txt'].map((p) => resolveSafe(FS, '/r', p));`, expect: [null, null, null, null, '/r/ok.txt'] },
    { name: 'deep_link_tree_performance', script: `const FS = { '/': { type: 'dir' }, '/w': { type: 'dir' } }; let p = '/w'; const segs = []; for (let i = 0; i < 300; i++) { p += '/d' + i; FS[p] = { type: 'dir' }; segs.push('d' + i); } FS[p + '/f.txt'] = { type: 'file' }; for (let i = 0; i < 39; i++) FS['/w/l' + i] = { type: 'link', target: i < 38 ? 'l' + (i + 1) : segs.join('/') }; const t0 = Date.now(); let r; for (let k = 0; k < 200; k++) r = resolveSafe(FS, '/w', 'l0/f.txt'); return r;`, expect: '/w/' + Array.from({ length: 300 }, (_, i) => 'd' + i).join('/') + '/f.txt' },
  ];
  return {
    slug, track: 'secure',
    title_en: 'Virtual File Server Path Guard', title_ar: 'حارس المسارات لخادم ملفات افتراضي',
    summary_en: 'Map untrusted, percent-encoded request paths to files in a virtual file system with symlinks — blocking traversal, encoding tricks, hidden files, link escapes and loops.',
    summary_ar: 'ربط مسارات الطلبات غير الموثوقة والمُرمَّزة بالنسبة المئوية بملفات نظام ملفات افتراضي يحوي روابط رمزية، مع منع تجاوز المجلدات وحيل الترميز والملفات المخفية والهروب عبر الروابط والحلقات.',
    prompt: `# Virtual File Server Path Guard

A static-file service exposes the directory \`root\` of an in-memory virtual file system. Requests arrive as raw, percent-encoded URL paths. Implement \`function resolveSafe(fs, root, requestPath)\` returning the real path of the file to serve, or \`null\` when the request must be refused (the caller answers 404 in both the "forbidden" and the "not found" case).

## The virtual file system
\`fs\` is a plain object whose keys are absolute, normalised paths (\`"/"\`, \`"/srv"\`, \`"/srv/public/index.html"\` — no trailing slash, no empty, \`.\` or \`..\` segments) and whose values are entries:
- \`{ "type": "dir" }\`
- \`{ "type": "file" }\`
- \`{ "type": "link", "target": "<path>" }\` — a symbolic link. \`target\` is absolute (starts with \`/\`) or relative to the directory that contains the link, and may contain \`.\` and \`..\` segments.

Every parent directory of an entry exists as a \`dir\` entry. \`root\` is an existing directory (possibly \`"/"\`); neither it nor its ancestors are links.

## Resolution rules
1. \`requestPath\` must be a string of at most 4096 characters.
2. Percent-decode it **exactly once** as UTF-8 (\`%2F\` → \`/\`, \`%2e\` → \`.\`, \`%25\` → \`%\`; \`+\` stays \`+\`). A malformed escape, or bytes that are not valid UTF-8, → refuse.
3. After decoding, refuse if it contains a backslash or any control character (U+0000–U+001F, U+007F).
4. Normalise lexically: split on \`/\`, ignore empty and \`.\` segments, and let \`..\` remove the previous segment. A \`..\` with nothing left to remove would leave the root → refuse (never clamp).
5. Refuse if any remaining segment starts with \`.\` (hidden files such as \`.env\`, \`.git\`).
6. Starting at \`root\`, walk the segments through \`fs\`. When a segment is a link, continue with its target: an absolute target restarts the walk at \`/\`, a relative one at the link's directory; \`..\` inside a target moves to the parent of the current real directory (never above \`/\`). Follow at most 40 links per request in total; needing a 41st → refuse. A missing entry, or a non-directory with more segments after it, → refuse.
7. The final real path must be \`root\` itself or lie below it (\`/srv/public-old\` is **not** below \`/srv/public\`), none of its segments below \`root\` may start with \`.\`, and it must be a \`file\` entry. Return that real path; otherwise refuse.

## Example
\`\`\`js
const fs = {
  "/": { type: "dir" }, "/srv": { type: "dir" }, "/srv/secret.txt": { type: "file" },
  "/srv/public": { type: "dir" }, "/srv/public/index.html": { type: "file" },
  "/srv/public/docs": { type: "dir" }, "/srv/public/docs/a b.txt": { type: "file" },
  "/srv/public/home": { type: "link", target: "index.html" },
  "/srv/public/escape": { type: "link", target: "../secret.txt" },
};
resolveSafe(fs, "/srv/public", "docs/a%20b.txt");   // → "/srv/public/docs/a b.txt"
resolveSafe(fs, "/srv/public", "home");             // → "/srv/public/index.html"
resolveSafe(fs, "/srv/public", "%2e%2e/secret.txt"); // → null
resolveSafe(fs, "/srv/public", "escape");           // → null (the real path is outside root)
\`\`\`

Do not modify \`fs\`. Do not use \`eval\` or \`Function\`.

${jsContract('resolveSafe')}`,
    output_type: 'js', difficulty: 4, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: STATIC, tests: { entry: 'resolveSafe', timeout_ms: 2000, weight: 10, cases: await finalizeCases(slug, 'resolveSafe', cases) } },
  };
}

// ════════════════════════════════════════════ 4. template render ═════════════════════════════════
async function templateTask() {
  const slug = 'secure-template-render';
  const D = { user: { name: 'Lina', bio: '<script>alert(1)</script>', quote: `"Tom" & 'Jerry'`, tags: ['a', 'b', 'c'], age: 31, admin: false, note: null, score: 0 }, title: '  Hello World  ', html: '<b>x</b>' };
  const t = (name, tpl, expect, data = D) => ({ name, args: [tpl, data], expect });
  const cases = [
    t('basic_substitution', 'Hi {{ user.name }}!', 'Hi Lina!'),
    t('whitespace_variants', '{{user.name}}|{{  user.name  }}|{{\tuser.name\n}}', 'Lina|Lina|Lina'),
    t('html_escaping_all_five', '<p>{{ user.bio }}</p><i title="{{ user.quote }}">', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p><i title="&quot;Tom&quot; &amp; &#39;Jerry&#39;">'),
    t('value_types', '{{ user.age }}/{{ user.admin }}/{{ user.note }}/{{ user.score }}/{{ user.tags }}/{{ user }}', '31/false//0//'),
    t('missing_values_empty', '[{{ nope }}][{{ user.nope }}][{{ user.name.first }}][{{ title.length }}]', '[][][][]'),
    t('array_own_properties', '{{ user.tags.length }}', '3'),
    t('filters', '{{ user.name | upper }} {{ user.name|lower }} [{{ title | trim }}] {{ user.bio | upper }}', 'LINA lina [Hello World] &lt;SCRIPT&gt;ALERT(1)&lt;/SCRIPT&gt;'),
    t('unknown_filter_renders_empty', '[{{ user.name | reverse }}][{{ user.name | toString }}]', '[][]'),
    t('json_filter_script_safe', '<script>var u = {{ user | json }};</script>', '<script>var u = {"name":"Lina","bio":"\\u003cscript\\u003ealert(1)\\u003c/script\\u003e","quote":"\\"Tom\\" \\u0026 \'Jerry\'","tags":["a","b","c"],"age":31,"admin":false,"note":null,"score":0};</script>'),
    t('json_filter_line_separators', '{{ s | json }}', '"a\\u2028b\\u2029c\\u003c/script\\u003e"', { s: 'a\u2028b\u2029c</script>' }),
    { name: 'json_filter_missing_and_unserialisable', script: `const cyc = { n: 1 }; cyc.self = cyc; return renderTemplate('{{ nope | json }}|{{ f | json }}|{{ b | json }}|{{ c | json }}|{{ n | json }}', { f: () => 1, b: 10n, c: cyc, n: null });`, expect: 'null|null|null|null|null' },
    t('invalid_tags_copied_verbatim', '{{ 1abc }} {{ a..b }} {{ a. }} {{ a b }} {{}} {{ a | }} {{ a | b | c }} {{ $x }}', '{{ 1abc }} {{ a..b }} {{ a. }} {{ a b }} {{}} {{ a | }} {{ a | b | c }} {{ $x }}'),
    t('unclosed_and_stray_braces', 'a {{ user.name b }} c {{ d } }} e {{', 'a {{ user.name b }} c {{ d } }} e {{'),
    t('triple_braces', '{{{ user.name }}}', '{Lina}'),
    t('values_are_not_re_rendered', '{{ a }}{{ b }}', '{{ secret }}{{&lt;b&gt;}}', { a: '{{ secret }}', b: '{{<b>}}', secret: 'TOP SECRET' }),
    t('data_injection_cannot_create_tags', '{{ a }}{{ b }}', '{{ secret }}', { a: '{{ sec', b: 'ret }}', secret: 'X' }),
    t('template_text_is_trusted_verbatim', '<b>{{ user.name }}</b> & <i>it\'s</i>', '<b>Lina</b> & <i>it\'s</i>'),
    t('prototype_chain_not_reachable', '[{{ toString }}][{{ user.constructor.name }}][{{ __proto__ }}][{{ user.hasOwnProperty }}][{{ user.tags.map }}][{{ constructor }}]', '[][][][][][]'),
    { name: 'own_forbidden_keys_ignored', script: `const data = JSON.parse('{"__proto__": {"x": "polluted"}, "constructor": "C", "prototype": "P", "o": {"constructor": {"name": "N"}}}'); return renderTemplate('[{{ __proto__.x }}][{{ constructor }}][{{ prototype }}][{{ o.constructor.name }}]', data);`, expect: '[][][][]' },
    { name: 'null_prototype_data', script: `const d = Object.create(null); d.name = 'Zed'; d.inner = Object.create(null); d.inner.v = '<v>'; return renderTemplate('{{ name }} {{ inner.v }} {{ inner.toString }}', d);`, expect: 'Zed &lt;v&gt; ' },
    { name: 'getters_on_prototype_ignored', script: `class User { constructor() { this.name = 'Own'; } get secret() { return 'LEAK'; } } return renderTemplate('{{ u.name }}[{{ u.secret }}]', { u: new User() });`, expect: 'Own[]' },
    { name: 'no_global_pollution', script: `renderTemplate('{{ __proto__.polluted }}{{ constructor.prototype.polluted }}', JSON.parse('{"__proto__": {"polluted": 1}}')); return [({}).polluted === undefined, Object.keys(Object.prototype).length];`, expect: [true, 0] },
    { name: 'linear_time_unclosed_braces', script: `const tpl = '{{'.repeat(100000); const out = renderTemplate(tpl, {}); return out === tpl;`, expect: true },
    { name: 'linear_time_unclosed_tags', script: `const tpl = '{{ a '.repeat(60000) + 'end'; return renderTemplate(tpl, { a: 1 }) === tpl;`, expect: true },
    { name: 'linear_time_long_paths', script: `const path = Array.from({ length: 30000 }, (_, i) => 'k' + i).join('.'); const tpl = ('{{ ' + path + ' | upper ').repeat(3) + '{{ ok }}'; return renderTemplate(tpl, { ok: 'done' }).endsWith('done');`, expect: true },
    { name: 'many_tags_large_template', script: `const parts = []; for (let i = 0; i < 40000; i++) parts.push('<li>{{ items.' + 'i' + (i % 50) + ' }}</li>'); const items = {}; for (let i = 0; i < 50; i++) items['i' + i] = i % 2 ? '<' + i + '>' : i; const out = renderTemplate(parts.join(''), { items }); return [out.length, out.slice(0, 40)];` },
  ];
  return {
    slug, track: 'secure',
    title_en: 'Injection-Proof Template Renderer', title_ar: 'مُصيِّر قوالب منيع ضد الحقن',
    summary_en: 'Render {{ placeholders }} with untrusted data: context escaping, a script-safe json filter, no prototype-chain access, no re-rendering and linear time on adversarial templates.',
    summary_ar: 'تصيير العناصر النائبة {{ }} ببيانات غير موثوقة: ترميز حسب السياق، ومرشّح json آمن داخل السكربت، ومنع الوصول إلى سلسلة النماذج الأولية، وعدم إعادة التصيير، وزمن خطي أمام القوالب العدائية.',
    prompt: `# Injection-Proof Template Renderer

E-mail and page templates are written by our staff (trusted) but filled with customer data (untrusted). Implement \`function renderTemplate(template, data)\`.

## Placeholders
A placeholder is \`{{\`, optional whitespace, a **path**, optionally whitespace + \`|\` + whitespace + a **filter name**, optional whitespace, \`}}\`. Whitespace means spaces, tabs, CR and LF.
- path: one or more identifiers separated by \`.\` (no spaces around the dots); identifier = \`[A-Za-z_][A-Za-z0-9_]*\`. Example: \`user.address.city\`.
- filter name: one identifier.

Scan the template from left to right. Where the text at the current position is a complete placeholder, replace it with its rendering and continue after it. Otherwise copy one character and continue with the next one (so \`{{ 1abc }}\`, \`{{ a..b }}\` and a lone \`{{\` stay as they are, and \`{{{ x }}}\` becomes \`{\` + value + \`}\`). Text produced by a placeholder is never scanned again. The template text itself is trusted and copied unchanged.

## Looking up a path
Start at \`data\`; for each identifier the current value must be a non-null object (plain object, null-prototype object or array) that has the identifier as an **own** property, and the identifier must not be \`__proto__\`, \`constructor\` or \`prototype\`. If any step fails, the value is *missing*.

## Rendering
- Text conversion: strings as they are; numbers, bigints and booleans via \`String()\`; everything else (missing, \`null\`, \`undefined\`, objects, arrays, functions, symbols) → \`""\`.
- No filter: the text, HTML-escaped. Filters \`upper\`, \`lower\`, \`trim\`: apply \`toUpperCase()\` / \`toLowerCase()\` / \`trim()\` to the text, then HTML-escape.
- Filter \`json\` (for use inside \`<script>\`): \`JSON.stringify(value)\` — or \`null\` if the value is missing or cannot be serialised (undefined, functions, cycles, bigints) — and then replace \`<\` \`>\` \`&\` U+2028 U+2029 with \`\\u003c\` \`\\u003e\` \`\\u0026\` \`\\u2028\` \`\\u2029\`. This output is **not** HTML-escaped.
- Any other filter name renders as \`""\`.
- HTML escaping: \`&\` → \`&amp;\`, \`<\` → \`&lt;\`, \`>\` → \`&gt;\`, \`"\` → \`&quot;\`, \`'\` → \`&#39;\` (exactly these five).

## Constraints
Templates can be about 1 MB and may be adversarial (thousands of unclosed \`{{\`, very long paths): rendering must take linear time. Do not use \`eval\`, \`Function\` or \`with\`, do not modify \`data\`, and never read inherited properties.

## Example
\`\`\`js
renderTemplate('<h1>{{ user.name | upper }}</h1><p>{{ user.bio }}</p>{{ user.constructor.name }}',
               { user: { name: 'Lina', bio: '<b>hi</b>' } });
// → '<h1>LINA</h1><p>&lt;b&gt;hi&lt;/b&gt;</p>'
\`\`\`

${jsContract('renderTemplate')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [...STATIC, { id: 'no_with', type: 'not_contains', pattern: '\\bwith\\s*\\(', regex: true, weight: 1, label_en: 'No with statement', label_ar: 'لا استخدام لعبارة with' }], tests: { entry: 'renderTemplate', timeout_ms: 2000, weight: 10, cases: await finalizeCases(slug, 'renderTemplate', cases) } },
  };
}

// ════════════════════════════════════════════ 5. settings patch ══════════════════════════════════
const SCHEMA = { type: 'object', properties: {
  displayName: { type: 'string', maxLength: 40 },
  theme: { type: 'enum', values: ['dark', 'light', 'system'] },
  fontScale: { type: 'number', min: 0.5, max: 3 },
  notifications: { type: 'object', properties: {
    email: { type: 'boolean' },
    digestHour: { type: 'integer', min: 0, max: 23 },
    channels: { type: 'array', maxItems: 3, items: { type: 'enum', values: ['email', 'sms', 'push'] } },
  } },
  tags: { type: 'array', maxItems: 5, items: { type: 'string', maxLength: 20 } },
  shortcuts: { type: 'array', maxItems: 3, items: { type: 'object', properties: { key: { type: 'string', maxLength: 1 }, action: { type: 'enum', values: ['save', 'open', 'close'] } } } },
} };
const CURRENT = { displayName: 'Rana', theme: 'dark', fontScale: 1, notifications: { email: true, digestHour: 8, channels: ['email'] }, tags: ['beta'] };
const patchCase = (name, patchExpr, expect) => ({ name, script: `const SCHEMA = ${JS(SCHEMA)}; const CURRENT = ${JS(CURRENT)}; const r = applyPatch(CURRENT, ${patchExpr}, SCHEMA); return r;`, expect });

async function patchTask() {
  const slug = 'secure-settings-patch';
  const merged = (over) => ({ ok: true, value: { ...CURRENT, ...over } });
  const cases = [
    patchCase('simple_leaf_update', `{ theme: 'light' }`, merged({ theme: 'light' })),
    patchCase('nested_merge_keeps_siblings', `{ notifications: { digestHour: 20 } }`, merged({ notifications: { email: true, digestHour: 20, channels: ['email'] } })),
    patchCase('null_deletes_key', `{ tags: null, notifications: { channels: null } }`, { ok: true, value: { displayName: 'Rana', theme: 'dark', fontScale: 1, notifications: { email: true, digestHour: 8 } } }),
    patchCase('arrays_replaced_wholesale', `{ tags: ['x', 'y'], notifications: { channels: ['sms', 'push'] } }`, merged({ tags: ['x', 'y'], notifications: { email: true, digestHour: 8, channels: ['sms', 'push'] } })),
    patchCase('empty_patch_returns_copy', `{}`, merged({})),
    patchCase('create_missing_object', `{ shortcuts: [{ key: 's', action: 'save' }, {}] }`, merged({ shortcuts: [{ key: 's', action: 'save' }, {}] })),
    patchCase('unknown_keys_rejected', `{ theme: 'light', isAdmin: true, notifications: { sms: true } }`, { ok: false, errors: ['unknown key: $.isAdmin', 'unknown key: $.notifications.sms'] }),
    patchCase('wrong_types_collected_and_sorted', `{ theme: 3, fontScale: '2', notifications: { email: 'yes', digestHour: 7.5 } }`, { ok: false, errors: ['invalid type: $.fontScale', 'invalid type: $.notifications.digestHour', 'invalid type: $.notifications.email', 'invalid value: $.theme'] }),
    patchCase('ranges_and_lengths', `{ fontScale: 3.5, displayName: 'x'.repeat(41), notifications: { digestHour: 24 }, tags: ['ok', 'y'.repeat(21)] }`, { ok: false, errors: ['invalid value: $.displayName', 'invalid value: $.fontScale', 'invalid value: $.notifications.digestHour', 'invalid value: $.tags.1'] }),
    patchCase('boundaries_inclusive', `{ fontScale: 0.5, notifications: { digestHour: 0 }, displayName: 'x'.repeat(40) }`, merged({ fontScale: 0.5, displayName: 'x'.repeat(40), notifications: { email: true, digestHour: 0, channels: ['email'] } })),
    patchCase('length_counts_code_points', `{ displayName: '😀'.repeat(40) }`, merged({ displayName: '😀'.repeat(40) })),
    patchCase('too_many_items', `{ tags: ['1', '2', '3', '4', '5', '6'], notifications: { channels: ['email', 'sms', 'push', 'email'] } }`, { ok: false, errors: ['invalid value: $.notifications.channels', 'invalid value: $.tags'] }),
    patchCase('array_items_validated', `{ tags: ['a', 7, null], shortcuts: [{ key: 'ab', action: 'jump' }, { key: 'k', action: 'open', extra: 1 }, 'x'] }`, { ok: false, errors: ['invalid type: $.shortcuts.2', 'invalid type: $.tags.1', 'invalid type: $.tags.2', 'invalid value: $.shortcuts.0.action', 'invalid value: $.shortcuts.0.key', 'unknown key: $.shortcuts.1.extra'] }),
    patchCase('enum_items_and_nulls', `{ notifications: { channels: [null, 'fax'] } }`, { ok: false, errors: ['invalid value: $.notifications.channels.0', 'invalid value: $.notifications.channels.1'] }),
    patchCase('proto_key_rejected', `JSON.parse('{"__proto__": {"isAdmin": true}, "theme": "light"}')`, { ok: false, errors: ['forbidden key: $.__proto__'] }),
    patchCase('nested_proto_key_rejected', `JSON.parse('{"notifications": {"__proto__": {"polluted": 1}, "email": false}}')`, { ok: false, errors: ['forbidden key: $.notifications.__proto__'] }),
    patchCase('constructor_prototype_rejected', `JSON.parse('{"constructor": {"prototype": {"polluted": "yes"}}, "shortcuts": [{"prototype": 1, "key": "s"}]}')`, { ok: false, errors: ['forbidden key: $.constructor', 'forbidden key: $.shortcuts.0.prototype'] }),
    patchCase('inherited_names_are_unknown_keys', `{ toString: 'x', valueOf: 1, hasOwnProperty: 2, notifications: { isPrototypeOf: 3 } }`, { ok: false, errors: ['unknown key: $.hasOwnProperty', 'unknown key: $.notifications.isPrototypeOf', 'unknown key: $.toString', 'unknown key: $.valueOf'] }),
    { name: 'no_prototype_pollution_after_attacks', script: `const SCHEMA = ${JS(SCHEMA)}; const CURRENT = ${JS(CURRENT)}; const attacks = ['{"__proto__": {"polluted": true}}', '{"notifications": {"__proto__": {"polluted": true}}}', '{"constructor": {"prototype": {"polluted": true}}}', '{"shortcuts": [{"__proto__": {"polluted": true}}]}', '{"tags": {"__proto__": [1]}}']; for (const a of attacks) applyPatch(CURRENT, JSON.parse(a), SCHEMA); const ok = applyPatch(CURRENT, { theme: 'light' }, SCHEMA); return [({}).polluted === undefined, Object.keys(Object.prototype).length, Object.getPrototypeOf(ok.value) === Object.prototype, Object.getPrototypeOf(ok.value.notifications) === Object.prototype, typeof ok.value.hasOwnProperty];`, expect: [true, 0, true, true, 'function'] },
    patchCase('root_must_be_plain_object', `[]`, { ok: false, errors: ['invalid type: $'] }),
    { name: 'non_plain_roots_rejected', script: `const SCHEMA = ${JS(SCHEMA)}; const CURRENT = ${JS(CURRENT)}; const evil = Object.create({ theme: 'light', isAdmin: true }); class P { constructor() { this.theme = 'light'; } } return [null, 'theme=light', 5, evil, new P(), new Date(0)].map((p) => JSON.stringify(applyPatch(CURRENT, p, SCHEMA)));`, expect: Array(6).fill('{"ok":false,"errors":["invalid type: $"]}') },
    patchCase('non_plain_nested_object_rejected', `{ notifications: Object.create({ email: false }) , shortcuts: [Object.create(null)] }`, { ok: false, errors: ['invalid type: $.notifications'] }),
    { name: 'inputs_not_mutated_and_not_shared', script: `const SCHEMA = ${JS(SCHEMA)}; const CURRENT = ${JS(CURRENT)}; const before = JSON.stringify(CURRENT); const patch = { tags: ['p', 'q'], notifications: { channels: ['sms'] } }; const pBefore = JSON.stringify(patch); const r = applyPatch(CURRENT, patch, SCHEMA); patch.tags.push('later'); patch.notifications.channels[0] = 'push'; r.value.notifications.email = 'changed'; return [JSON.stringify(CURRENT) === before, JSON.stringify(r.value.tags), r.value.notifications.channels[0], r.value !== CURRENT, CURRENT.notifications.email, JSON.stringify(patch) !== pBefore];`, expect: [true, '["p","q"]', 'sms', true, true, true] },
    { name: 'failed_result_has_no_value', script: `const SCHEMA = ${JS(SCHEMA)}; const r = applyPatch(${JS(CURRENT)}, { theme: 'neon' }, SCHEMA); return [r.ok, 'value' in r, r.errors];`, expect: [false, false, ['invalid value: $.theme']] },
    { name: 'delete_then_recreate_object', script: `const SCHEMA = ${JS(SCHEMA)}; const a = applyPatch(${JS(CURRENT)}, { notifications: null }, SCHEMA); const b = applyPatch(a.value, { notifications: { email: false } }, SCHEMA); return [a.value.notifications === undefined, b];`, expect: [true, { ok: true, value: { displayName: 'Rana', theme: 'dark', fontScale: 1, tags: ['beta'], notifications: { email: false } } }] },
    { name: 'deeply_nested_junk_not_traversed', script: `const SCHEMA = ${JS(SCHEMA)}; let junk = {}; for (let i = 0; i < 20000; i++) junk = { a: junk }; const r = applyPatch(${JS(CURRENT)}, { theme: junk, notifications: { email: junk } }, SCHEMA); return r;`, expect: { ok: false, errors: ['invalid type: $.notifications.email', 'invalid value: $.theme'] } },
  ];
  return {
    slug, track: 'secure',
    title_en: 'Prototype-Pollution-Proof Settings Patch', title_ar: 'تعديل إعدادات منيع ضد تلويث النماذج الأولية',
    summary_en: 'Validate an untrusted JSON settings patch against a schema and deep-merge it — without prototype pollution, input mutation or inherited-key confusion.',
    summary_ar: 'التحقق من رقعة إعدادات JSON غير موثوقة وفق مخطط ودمجها دمجًا عميقًا — دون تلويث النماذج الأولية أو تعديل المدخلات أو الخلط بالمفاتيح الموروثة.',
    prompt: `# Prototype-Pollution-Proof Settings Patch

\`PATCH /api/settings\` receives a JSON body from the browser and merges it into the user's stored settings. Implement \`function applyPatch(current, patch, schema)\`.

## Schema
\`schema\` is trusted and describes the settings object. Node types:
- \`{ type: "object", properties: { name: node, … } }\`
- \`{ type: "string", maxLength? }\` — length counted in Unicode code points
- \`{ type: "integer", min?, max? }\`, \`{ type: "number", min?, max? }\` (bounds inclusive)
- \`{ type: "boolean" }\`, \`{ type: "enum", values: [ … ] }\`
- \`{ type: "array", items: node, maxItems? }\`

\`current\` is a valid settings object (plain objects, arrays and primitives). \`patch\` is untrusted — usually the result of \`JSON.parse\` of the request body, so it can contain own properties named \`__proto__\`, \`constructor\` or \`prototype\`.

## Merge semantics
- The patch root must be a **plain object**: an object whose prototype is \`Object.prototype\` or \`null\` (arrays, class instances, dates, objects with any other prototype are not plain).
- For an \`object\` node, each **own enumerable** key of the patch object is processed:
  - \`__proto__\`, \`constructor\`, \`prototype\` → error \`forbidden key\` (checked first; nothing else is reported for that key);
  - a key that is not an **own** property of \`properties\` → \`unknown key\` (\`toString\`, \`hasOwnProperty\` … are unknown keys too);
  - value \`null\` → the key is removed from the result;
  - an \`object\` child is merged recursively into the current child (or into \`{}\` if the current value has no such key); any other child replaces the current value.
- Arrays replace the current array as a whole; each item is validated against \`items\` (items of type \`object\` are validated as patches merged into \`{}\`).

## Validation errors
Each error is a string \`"<kind>: <path>"\`, where path is \`$\` followed by \`.key\` / \`.index\` segments (\`$.notifications.digestHour\`, \`$.tags.3\`, \`$.shortcuts.0.key\`), and kind is one of:
- \`forbidden key\`, \`unknown key\` — see above;
- \`invalid type\` — object node: not a plain object; string: not a string; integer: not a number or not an integer; number: not a finite number; boolean: not a boolean; array: not an array. (\`null\` as an array item is checked like any other value.)
- \`invalid value\` — string longer than \`maxLength\`; number/integer outside \`min\`/\`max\`; enum value not strictly equal (\`===\`) to one of \`values\`; array with more than \`maxItems\` items (its items are then not checked).
Do not descend into a value that already failed its type check. Report **every** error, not just the first.

## Return value
- Success: \`{ ok: true, value }\` where \`value\` is a **new** object — later changes to \`patch\` or to \`current\` must not affect it, and its objects must be ordinary objects (prototype \`Object.prototype\`).
- Failure: \`{ ok: false, errors }\` with \`errors\` sorted ascending by UTF-16 code units (default \`Array.prototype.sort\` order); there is no \`value\` property.
Never modify \`current\`, \`patch\` or \`schema\`, never write to any prototype, and never call \`eval\` or \`Function\`.

## Example
\`\`\`js
const schema = { type: "object", properties: {
  theme: { type: "enum", values: ["dark", "light"] },
  notifications: { type: "object", properties: { email: { type: "boolean" }, digestHour: { type: "integer", min: 0, max: 23 } } },
} };
const current = { theme: "dark", notifications: { email: true, digestHour: 8 } };
applyPatch(current, { notifications: { digestHour: 20 } }, schema);
// → { ok: true, value: { theme: "dark", notifications: { email: true, digestHour: 20 } } }
applyPatch(current, JSON.parse('{"__proto__": {"isAdmin": true}, "theme": "blue"}'), schema);
// → { ok: false, errors: ["forbidden key: $.__proto__", "invalid value: $.theme"] }
\`\`\`

${jsContract('applyPatch')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: STATIC, tests: { entry: 'applyPatch', timeout_ms: 2000, weight: 10, cases: await finalizeCases(slug, 'applyPatch', cases) } },
  };
}

const tasks = [];
for (const make of [encoderTask, redirectTask, vfsTask, templateTask, patchTask]) tasks.push(await make());
writeTrack('secure', tasks);
