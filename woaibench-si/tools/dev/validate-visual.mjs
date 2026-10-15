#!/usr/bin/env node
// WOAIBench.si — DEV ONLY validator for the visual-track tasks (ui, games, 3d, motion, dataviz).
// Never deployed (tools/ is blocked by .htaccess).
//
//   NODE_PATH=/path/to/node_modules node tools/dev/validate-visual.mjs [track|slug ...]
//        [--no-browser] [--shots=DIR] [--trivial-only] [--json] [--verbose]
//
// For every task in sql/seed/tasks/{ui,games,3d,motion,dataviz}.json:
//   1. structural validation (required fields, unique slugs / check ids, known check types, weights > 0,
//      bilingual labels, slug prefixed with its track, the prompt mentions every id a check relies on);
//   2. STATIC checks on tools/dev/references/<slug>.html with a JS re-implementation of the
//      semantics in ARCHITECTURE §5.1 (and app/eval/StaticChecks.php through the php CLI when present);
//   3. BROWSER checks: the reference is served with the same sandbox CSP as /render/{id}
//      (opaque origin, no storage, no modals) and opened in Chromium at 1280×800 and 390×844;
//      every browser check must hold, plus task-specific functional assertions (EXTRAS below)
//      that prove the oracle really works (keys move the player, sorting sorts, maths is right …);
//   4. discriminative power: a trivial page (<html><body>Hello</body></html>) must score low.
// Exit code 0 only when every reference passes everything and every trivial score is < 35.

import { createRequire } from 'node:module';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import http from 'node:http';
import path from 'node:path';
import os from 'node:os';

const __filename = fileURLToPath(import.meta.url);
const ROOT = path.resolve(path.dirname(__filename), '../..');
const TASK_DIR = path.join(ROOT, 'sql/seed/tasks');
const REF_DIR = path.join(ROOT, 'tools/dev/references');
const TRACKS = ['ui', 'games', '3d', 'motion', 'dataviz'];
const STATIC_TYPES = ['contains', 'not_contains', 'selector', 'max_bytes', 'min_bytes', 'no_external', 'viewport',
  'lang_attr', 'dir_rtl', 'html_parses', 'single_file', 'regex_count', 'js_parses'];
const BROWSER_TYPES = ['loads', 'no_errors', 'nonblank', 'selector', 'text', 'animates', 'fps', 'responsive',
  'keyboard', 'click', 'rtl', 'a11y_basic', 'canvas'];
const CDN = 'https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com https://esm.sh https://cdn.skypack.dev https://threejs.org https://fonts.googleapis.com https://fonts.gstatic.com';
const TRIVIAL = '<html><body>Hello</body></html>';
const TRIVIAL_MAX = 35; // a trivial page must not reach this auto score on any task

// ───────────────────────────── CLI ─────────────────────────────
const argv = process.argv.slice(2);
const flags = Object.fromEntries(argv.filter((a) => a.startsWith('--')).map((a) => {
  const [k, v] = a.slice(2).split('=');
  return [k, v === undefined ? true : v];
}));
const filters = argv.filter((a) => !a.startsWith('--'));
const VERBOSE = !!flags.verbose;
const SHOTS = typeof flags.shots === 'string' ? path.resolve(flags.shots) : null;

function loadPlaywright() {
  const bases = [path.join(process.cwd(), 'noop.js'), __filename];
  for (const p of (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean)) bases.push(path.join(p, '..', 'noop.js'));
  for (const b of bases) {
    try { return createRequire(b)('playwright'); } catch (e) { /* try next */ }
  }
  throw new Error('playwright not found: run from a folder that has node_modules/playwright or set NODE_PATH');
}

// ───────────────────────────── HTML tokenizer (shared by static checks) ─────────────────────────────
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'keygen']);
const RAW = new Set(['script', 'style', 'textarea', 'title']);
const OPTIONAL_CLOSE = new Set(['html', 'head', 'body', 'p', 'li', 'dt', 'dd', 'option', 'optgroup', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'colgroup', 'caption', 'rt', 'rp']);
const CLOSES_P = new Set(['address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul']);

/** Tokenize HTML into start/end tags + text, skipping comments and raw-text element bodies. */
function tokenize(html) {
  const out = [];
  let i = 0;
  const n = html.length;
  while (i < n) {
    const lt = html.indexOf('<', i);
    if (lt < 0) { out.push({ t: 'text', v: html.slice(i) }); break; }
    if (lt > i) out.push({ t: 'text', v: html.slice(i, lt) });
    if (html.startsWith('<!--', lt)) { const e = html.indexOf('-->', lt + 4); i = e < 0 ? n : e + 3; continue; }
    if (html[lt + 1] === '!' || html[lt + 1] === '?') { const e = html.indexOf('>', lt); i = e < 0 ? n : e + 1; continue; }
    if (html[lt + 1] === '/') {
      const m = /^<\/([a-zA-Z][a-zA-Z0-9:-]*)[^>]*>/.exec(html.slice(lt, lt + 200));
      if (!m) { out.push({ t: 'text', v: '<' }); i = lt + 1; continue; }
      out.push({ t: 'end', name: m[1].toLowerCase() });
      i = lt + m[0].length;
      continue;
    }
    if (!/[a-zA-Z]/.test(html[lt + 1] || '')) { out.push({ t: 'text', v: '<' }); i = lt + 1; continue; }
    // start tag
    let j = lt + 1;
    while (j < n && /[a-zA-Z0-9:-]/.test(html[j])) j++;
    const name = html.slice(lt + 1, j).toLowerCase();
    const attrs = {};
    let self = false;
    while (j < n && html[j] !== '>') {
      if (/\s/.test(html[j])) { j++; continue; }
      if (html[j] === '/') { self = true; j++; continue; }
      let k = j;
      while (k < n && !/[\s=>]/.test(html[k]) && !(html[k] === '/' && html[k + 1] === '>')) k++;
      const an = html.slice(j, k).toLowerCase();
      j = k;
      while (j < n && /\s/.test(html[j])) j++;
      let val = '';
      if (html[j] === '=') {
        j++;
        while (j < n && /\s/.test(html[j])) j++;
        if (html[j] === '"' || html[j] === "'") {
          const q = html[j];
          const e = html.indexOf(q, j + 1);
          val = html.slice(j + 1, e < 0 ? n : e);
          j = e < 0 ? n : e + 1;
        } else {
          let e = j;
          while (e < n && !/[\s>]/.test(html[e])) e++;
          val = html.slice(j, e);
          j = e;
        }
      }
      if (an) { attrs[an] = val; self = false; }
    }
    i = j + 1;
    out.push({ t: 'start', name, attrs, self });
    if (RAW.has(name) && !self) {
      const re = new RegExp('</' + name + '\\s*>', 'i');
      const rest = html.slice(i);
      const m = re.exec(rest);
      const body = m ? rest.slice(0, m.index) : rest;
      out.push({ t: 'raw', name, v: body });
      if (m) { out.push({ t: 'end', name }); i += m.index + m[0].length; } else i = n;
    }
  }
  return out;
}

/** Heuristic parse-error count comparable to libxml's HTML parser (mis-nesting, stray ends, bare &). */
function htmlErrors(html) {
  const toks = tokenize(html);
  const stack = [];
  const errors = [];
  for (const tk of toks) {
    if (tk.t === 'text') {
      const bad = tk.v.match(/&(?!#\d+;|#x[0-9a-fA-F]+;|[a-zA-Z][a-zA-Z0-9]*;)/g);
      if (bad) errors.push(`bare & in text (${bad.length})`);
    } else if (tk.t === 'start') {
      if (CLOSES_P.has(tk.name) && stack.includes('p')) {
        while (stack.length && stack[stack.length - 1] !== 'p' && OPTIONAL_CLOSE.has(stack[stack.length - 1])) stack.pop();
        if (stack[stack.length - 1] === 'p') stack.pop();
      }
      if (tk.name === 'li' && stack[stack.length - 1] === 'li') stack.pop();
      if ((tk.name === 'td' || tk.name === 'th') && ['td', 'th'].includes(stack[stack.length - 1])) stack.pop();
      if (tk.name === 'tr') { while (['td', 'th', 'tr'].includes(stack[stack.length - 1])) stack.pop(); }
      if (tk.name === 'option' && stack[stack.length - 1] === 'option') stack.pop();
      if ((tk.name === 'dt' || tk.name === 'dd') && ['dt', 'dd'].includes(stack[stack.length - 1])) stack.pop();
      if (!VOID.has(tk.name) && !tk.self) stack.push(tk.name);
    } else if (tk.t === 'end') {
      if (VOID.has(tk.name)) continue;
      const idx = stack.lastIndexOf(tk.name);
      if (idx < 0) { errors.push(`unexpected </${tk.name}>`); continue; }
      for (let k = stack.length - 1; k > idx; k--) if (!OPTIONAL_CLOSE.has(stack[k])) errors.push(`<${stack[k]}> closed implicitly by </${tk.name}>`);
      stack.length = idx;
    }
  }
  for (const s of stack) if (!OPTIONAL_CLOSE.has(s)) errors.push(`<${s}> never closed`);
  return errors;
}

/** Parse a simple compound selector list: tag, #id, .class, [attr], [attr=val], tag.class (comma = union). */
function parseSimpleSelector(sel) {
  return sel.split(',').map((part) => {
    const s = part.trim();
    const m = /^([a-zA-Z][a-zA-Z0-9-]*|\*)?((?:#[\w-]+|\.[\w-]+|\[[^\]]+\])*)$/.exec(s);
    if (!m) throw new Error(`unsupported static selector "${s}"`);
    const out = { tag: m[1] && m[1] !== '*' ? m[1].toLowerCase() : null, id: null, classes: [], attrs: [] };
    for (const t of m[2].match(/#[\w-]+|\.[\w-]+|\[[^\]]+\]/g) || []) {
      if (t[0] === '#') out.id = t.slice(1);
      else if (t[0] === '.') out.classes.push(t.slice(1));
      else {
        const am = /^\[\s*([^\s=\]]+)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\]\s]*)))?\s*\]$/.exec(t);
        if (!am) throw new Error(`bad attribute selector ${t}`);
        out.attrs.push({ name: am[1].toLowerCase(), val: am[2] ?? am[3] ?? am[4] ?? null });
      }
    }
    return out;
  });
}

function matchSimple(tag, sels) {
  return sels.some((s) => {
    if (s.tag && tag.name !== s.tag) return false;
    if (s.id && tag.attrs.id !== s.id) return false;
    const cls = (tag.attrs.class || '').split(/\s+/).filter(Boolean);
    if (s.classes.some((c) => !cls.includes(c))) return false;
    return s.attrs.every((a) => (a.name in tag.attrs) && (a.val === null || tag.attrs[a.name] === a.val));
  });
}

const URL_ATTRS = ['src', 'href', 'xlink:href', 'poster', 'srcset', 'data', 'action', 'formaction', 'background'];
const LINK_LOADS = ['stylesheet', 'icon', 'preload', 'modulepreload', 'prefetch', 'prerender', 'manifest', 'preconnect', 'dns-prefetch', 'apple-touch-icon', 'mask-icon', 'import'];
/** Resource URLs the page would load (navigation links like <a href> are not loads), as in StaticChecks.php. */
function urlRefs(html) {
  const refs = [];
  for (const tk of tokenize(html)) {
    if (tk.t === 'start') {
      if (tk.name === 'link' && tk.attrs.rel && !tk.attrs.rel.toLowerCase().split(/\s+/).some((r) => LINK_LOADS.includes(r))) continue;
      for (const a of URL_ATTRS) if (a in tk.attrs) {
        if ((tk.name === 'a' || tk.name === 'area') && a === 'href') continue;
        if (a === 'data' && tk.name !== 'object') continue;
        const vals = a === 'srcset' ? tk.attrs[a].split(',').map((x) => x.trim().split(/\s+/)[0]) : [tk.attrs[a].trim()];
        for (const v of vals) refs.push({ attr: a, tag: tk.name, v });
      }
    } else if (tk.t === 'raw' && tk.name === 'style') {
      for (const m of tk.v.matchAll(/url\(\s*['"]?([^'")]+)['"]?\s*\)|@import\s+['"]([^'"]+)['"]/g)) refs.push({ attr: 'css', tag: 'style', v: (m[1] || m[2]).trim() });
    } else if (tk.t === 'raw' && tk.name === 'script') {
      for (const m of tk.v.matchAll(/(?:\bfrom\s*|\bimport\s*\(\s*|\bfetch\s*\(\s*|\bimport\s+|new\s+(?:Worker|WebSocket|EventSource|Audio)\s*\(\s*)['"`]((?:https?:|wss?:)?\/\/[^'"`\s]+)['"`]/g)) refs.push({ attr: 'js', tag: 'script', v: m[1] });
    }
  }
  return refs;
}

function hostOf(u) {
  try { return new URL(u.startsWith('//') ? 'https:' + u : u).hostname.toLowerCase(); } catch (e) { return '?'; }
}

/** JS re-implementation of the static check semantics (ARCHITECTURE §5.1). Returns {pass, score, detail}. */
function runStatic(c, html) {
  const ok = (pass, detail = '') => ({ pass, score: pass ? 1 : 0, detail });
  const reFlags = (extra = '') => (c.ci ? 'i' : '') + extra;
  switch (c.type) {
    case 'contains':
    case 'not_contains': {
      const found = c.regex ? new RegExp(c.pattern, reFlags()).test(html)
        : (c.ci ? html.toLowerCase().includes(c.pattern.toLowerCase()) : html.includes(c.pattern));
      return c.type === 'contains' ? ok(found, found ? 'found' : 'missing') : ok(!found, found ? 'forbidden pattern found' : 'absent');
    }
    case 'selector': {
      const sels = parseSimpleSelector(c.sel);
      const count = tokenize(html).filter((t) => t.t === 'start' && matchSimple(t, sels)).length;
      const min = c.min ?? 1;
      const pass = count >= min && (c.max === undefined || count <= c.max);
      return ok(pass, `${count} × ${c.sel}`);
    }
    case 'max_bytes': { const b = Buffer.byteLength(html); return ok(b <= c.value, `${b} bytes`); }
    case 'min_bytes': { const b = Buffer.byteLength(html); return ok(b >= c.value, `${b} bytes`); }
    case 'no_external': {
      const allow = (c.allow || []).map((h) => h.toLowerCase());
      const bad = urlRefs(html).filter((r) => /^((https?|wss?):)?\/\//i.test(r.v) && hostOf(r.v) !== 'www.w3.org'
        && !allow.some((h) => hostOf(r.v) === h || hostOf(r.v).endsWith('.' + h)));
      return ok(bad.length === 0, bad.length ? bad.slice(0, 3).map((r) => r.v).join(' ') : 'no external refs');
    }
    case 'single_file': {
      const bad = urlRefs(html).filter((r) => r.v !== '' && !r.v.startsWith('#') && !r.v.startsWith('//') && !r.v.includes('${') && !/^[a-z][a-z0-9+.-]*:/i.test(r.v));
      return ok(bad.length === 0, bad.length ? bad.slice(0, 3).map((r) => r.v).join(' ') : 'self-contained');
    }
    case 'viewport': return ok(/<meta[^>]+name\s*=\s*["']?viewport/i.test(html));
    case 'lang_attr': {
      const t = tokenize(html).find((x) => x.t === 'start' && x.name === 'html');
      const v = t && t.attrs.lang ? t.attrs.lang : '';
      return ok(v !== '' && (!c.value || v.toLowerCase().startsWith(String(c.value).toLowerCase())), v || 'no lang');
    }
    case 'dir_rtl': {
      const t = tokenize(html).find((x) => x.t === 'start' && x.name === 'html');
      return ok(!!t && (t.attrs.dir || '').toLowerCase() === 'rtl');
    }
    case 'html_parses': {
      const errs = htmlErrors(html);
      const max = c.max_errors ?? c.threshold ?? 0;
      return ok(errs.length <= max, errs.length ? `${errs.length}: ${errs.slice(0, 3).join('; ')}` : '0 errors');
    }
    case 'regex_count': {
      const n = (html.match(new RegExp(c.pattern, reFlags('g'))) || []).length;
      return ok(n >= (c.min ?? 1), `${n} matches`);
    }
    case 'js_parses': return ok(true, 'n/a for html');
    default: return ok(false, 'unknown type');
  }
}

// ───────────────────────────── structural validation ─────────────────────────────
function validateTask(task, track, seen) {
  const errs = [];
  const req = ['slug', 'track', 'title_en', 'title_ar', 'summary_en', 'summary_ar', 'prompt', 'output_type', 'difficulty', 'visibility', 'rubric', 'checks'];
  for (const k of req) if (task[k] === undefined || task[k] === '') errs.push(`missing ${k}`);
  if (task.track !== track) errs.push(`track "${task.track}" != file track "${track}"`);
  if (!String(task.slug || '').startsWith(track + '-')) errs.push('slug must start with track prefix');
  if (!/^[a-z0-9-]{3,120}$/.test(task.slug || '')) errs.push('slug must be [a-z0-9-]');
  if (seen.has(task.slug)) errs.push('duplicate slug'); seen.add(task.slug);
  if (task.output_type !== 'html') errs.push('visual tasks must be output_type html');
  if (![1, 2, 3, 4].includes(task.difficulty)) errs.push('difficulty must be 1..4');
  if (!['public', 'holdout'].includes(task.visibility)) errs.push('bad visibility');
  if (!(task.auto_weight === null || (task.auto_weight >= 0 && task.auto_weight <= 1))) errs.push('auto_weight must be null or 0..1');
  for (const k of ['title_ar', 'summary_ar']) if (task[k] && !/[؀-ۿ]/.test(task[k])) errs.push(`${k} is not Arabic`);
  if (!Array.isArray(task.rubric) || task.rubric.length < 4 || task.rubric.length > 6) errs.push('rubric must have 4-6 criteria');
  const keys = new Set();
  for (const c of task.rubric || []) {
    for (const k of ['key', 'label_en', 'label_ar', 'weight', 'guide']) if (c[k] === undefined || c[k] === '') errs.push(`rubric criterion missing ${k}`);
    if (keys.has(c.key)) errs.push(`duplicate rubric key ${c.key}`); keys.add(c.key);
    if (!(c.weight > 0)) errs.push(`rubric ${c.key} weight must be > 0`);
    if (c.label_ar && !/[؀-ۿ]/.test(c.label_ar)) errs.push(`rubric ${c.key} label_ar not Arabic`);
    if ((c.guide || '').length < 60) errs.push(`rubric ${c.key} guide too short`);
  }
  const ids = new Set();
  const checks = task.checks || {};
  for (const k of Object.keys(checks)) if (!['static', 'browser'].includes(k)) errs.push(`unexpected checks.${k}`);
  for (const [kind, types] of [['static', STATIC_TYPES], ['browser', BROWSER_TYPES]]) {
    if (!Array.isArray(checks[kind]) || checks[kind].length === 0) { errs.push(`checks.${kind} must be a non-empty array`); continue; }
    for (const c of checks[kind]) {
      if (!c.id) errs.push(`${kind} check without id`);
      if (ids.has(c.id)) errs.push(`duplicate check id ${c.id}`); ids.add(c.id);
      if (!types.includes(c.type)) errs.push(`${c.id}: unknown ${kind} type ${c.type}`);
      if (!(c.weight > 0)) errs.push(`${c.id}: weight must be > 0`);
      if (!c.label_en || !c.label_ar) errs.push(`${c.id}: needs label_en + label_ar`);
      if (c.label_ar && !/[؀-ۿ]/.test(c.label_ar)) errs.push(`${c.id}: label_ar not Arabic`);
      if (['contains', 'not_contains', 'regex_count'].includes(c.type) && typeof c.pattern !== 'string') errs.push(`${c.id}: needs pattern`);
      if (c.regex || c.type === 'regex_count') { try { new RegExp(c.pattern); } catch (e) { errs.push(`${c.id}: bad regex ${e.message}`); } }
      if (c.type === 'selector' && typeof c.sel !== 'string') errs.push(`${c.id}: needs sel`);
      if (kind === 'static' && c.type === 'selector') { try { parseSimpleSelector(c.sel); } catch (e) { errs.push(`${c.id}: ${e.message}`); } }
      if (c.type === 'text' && typeof c.contains !== 'string') errs.push(`${c.id}: needs contains`);
      if (c.type === 'keyboard' && !(Array.isArray(c.keys) && c.keys.length)) errs.push(`${c.id}: needs keys[]`);
      if (c.type === 'fps' && !(c.min > 0)) errs.push(`${c.id}: needs min`);
      if (['max_bytes', 'min_bytes'].includes(c.type) && !(c.value > 0)) errs.push(`${c.id}: needs value`);
      // Fairness: every id / class a check relies on must be named in the prompt.
      const sel = c.sel || '';
      for (const m of sel.matchAll(/([#.])([\w-]+)/g)) {
        if (!task.prompt.includes(m[2])) errs.push(`${c.id}: prompt never mentions "${m[1]}${m[2]}"`);
      }
      // Literal UI text must be dictated by the prompt; values with digits may be computed from the prompt's data.
      if (c.type === 'text' && !/\d/.test(c.contains) && !task.prompt.toLowerCase().includes(c.contains.toLowerCase())) errs.push(`${c.id}: prompt never states required text "${c.contains}"`);
    }
  }
  for (const t of ['loads', 'no_errors', 'nonblank', 'responsive']) if (!(checks.browser || []).some((c) => c.type === t)) errs.push(`missing browser check ${t}`);
  return errs;
}

// ───────────────────────────── PHP StaticChecks (if the engine module has shipped it) ─────────────────────────────
function phpStatic(task, refPath) {
  const file = path.join(ROOT, 'app/eval/StaticChecks.php');
  if (!existsSync(file)) return null;
  const tmp = path.join(os.tmpdir(), `wbsc-${process.pid}-${task.slug}.php`);
  writeFileSync(tmp, `<?php
declare(strict_types=1);
define('WB', true);
define('WB_ROOT', ${JSON.stringify(ROOT)});
foreach (['helpers'] as $f) { $p = WB_ROOT . "/app/core/$f.php"; if (is_file($p)) require_once $p; }
require_once WB_ROOT . '/app/eval/StaticChecks.php';
$checks = json_decode((string) file_get_contents('php://stdin'), true);
$code = (string) file_get_contents(${JSON.stringify(refPath)});
echo json_encode(\\WB\\Eval\\StaticChecks::run($code, 'html', $checks));
`);
  const r = spawnSync('php', [tmp], { input: JSON.stringify(task.checks.static), encoding: 'utf8', timeout: 20000 });
  if (r.status !== 0 || !r.stdout.trim().startsWith('[') && !r.stdout.trim().startsWith('{')) {
    return { error: (r.stderr || r.stdout || 'php failed').slice(0, 400) };
  }
  try {
    const res = JSON.parse(r.stdout);
    const list = Array.isArray(res) ? res : (res.checks || res.results || Object.values(res));
    return { results: list };
  } catch (e) { return { error: 'bad php json: ' + r.stdout.slice(0, 200) }; }
}

// ───────────────────────────── sandboxed static server ─────────────────────────────
function startServer() {
  const csp = [
    'sandbox allow-scripts allow-pointer-lock', "default-src 'none'",
    `script-src 'unsafe-inline' 'unsafe-eval' blob: data: ${CDN}`, `style-src 'unsafe-inline' ${CDN}`,
    `img-src data: blob: ${CDN}`, `font-src data: ${CDN}`, 'media-src data: blob:', `connect-src data: blob: ${CDN}`,
    'worker-src blob: data:', 'child-src blob: data:', "form-action 'none'",
  ].join('; ');
  const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    let body = null;
    if (u.pathname === '/trivial') body = TRIVIAL;
    const m = /^\/ref\/([a-z0-9-]+)$/.exec(u.pathname);
    if (m && existsSync(path.join(REF_DIR, m[1] + '.html'))) body = readFileSync(path.join(REF_DIR, m[1] + '.html'));
    if (body === null) { res.writeHead(404); res.end('nope'); return; }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': csp, 'X-Content-Type-Options': 'nosniff' });
    res.end(body);
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

// ───────────────────────────── pixel helpers ─────────────────────────────
let helperPage = null;
async function pixelStats(a, b = null) {
  return helperPage.evaluate(async ([ia, ib]) => {
    const load = async (s) => createImageBitmap(await (await fetch('data:image/png;base64,' + s)).blob());
    const data = (bmp) => {
      const c = new OffscreenCanvas(bmp.width, bmp.height);
      const x = c.getContext('2d');
      x.drawImage(bmp, 0, 0);
      return x.getImageData(0, 0, bmp.width, bmp.height).data;
    };
    const A = await load(ia);
    const da = data(A);
    const hist = new Map();
    let total = 0;
    for (let i = 0; i < da.length; i += 8) {
      const k = (da[i] >> 3) << 10 | (da[i + 1] >> 3) << 5 | (da[i + 2] >> 3);
      hist.set(k, (hist.get(k) || 0) + 1);
      total++;
    }
    let mode = 0;
    for (const v of hist.values()) mode = Math.max(mode, v);
    const out = { distinct: 1 - mode / total, colors: hist.size };
    if (ib) {
      const B = await load(ib);
      if (B.width !== A.width || B.height !== A.height) { out.diff = 1; return out; }
      const db = data(B);
      let d = 0;
      for (let i = 0; i < da.length; i += 4) {
        if (Math.abs(da[i] - db[i]) > 8 || Math.abs(da[i + 1] - db[i + 1]) > 8 || Math.abs(da[i + 2] - db[i + 2]) > 8) d++;
      }
      out.diff = d / (da.length / 4);
    }
    return out;
  }, [a.toString('base64'), b ? b.toString('base64') : null]);
}

async function snapshot(page) {
  const shot = await page.screenshot({ type: 'png' });
  const text = await page.evaluate(() => (document.body ? document.body.innerText : '') + '|' + (document.body ? document.body.innerHTML.length : 0)
    + '|' + [...document.querySelectorAll('[aria-pressed],[aria-checked],[aria-expanded],[aria-selected],[hidden],[class]')].map((e) => (e.getAttribute('aria-pressed') || '') + (e.getAttribute('aria-checked') || '') + (e.getAttribute('aria-expanded') || '') + (e.getAttribute('aria-selected') || '') + (e.hidden ? 'h' : '') + e.className).join(','));
  return { shot, text };
}

async function changed(page, before) {
  const after = await snapshot(page);
  if (after.text !== before.text) return { changed: true, detail: 'DOM changed' };
  const s = await pixelStats(before.shot, after.shot);
  return { changed: s.diff > 0.0003, detail: `pixels changed ${(s.diff * 100).toFixed(3)}%` };
}

// ───────────────────────────── browser checks ─────────────────────────────
async function runBrowserCheck(c, page, ctx) {
  const ok = (pass, detail = '', score = null) => ({ pass, score: score ?? (pass ? 1 : 0), detail });
  switch (c.type) {
    case 'loads': return ok(ctx.loaded, ctx.loaded ? `${ctx.loadMs} ms` : 'did not load');
    case 'no_errors': return ok(ctx.errors.length === 0, ctx.errors.slice(0, 3).join(' | ') || '0 errors');
    case 'nonblank': {
      const s = await pixelStats(await page.screenshot({ type: 'png' }));
      return ok(s.distinct > 0.01, `${(s.distinct * 100).toFixed(1)}% non-background, ${s.colors} colours`);
    }
    case 'selector': {
      const n = await page.evaluate((sel) => document.querySelectorAll(sel).length, c.sel);
      return ok(n >= (c.min ?? 1), `${n} × ${c.sel}`);
    }
    case 'text': {
      const t = await page.evaluate(() => document.body ? document.body.innerText : '');
      const has = c.ci ? t.toLowerCase().includes(c.contains.toLowerCase()) : t.includes(c.contains);
      return ok(has, has ? 'found' : `missing "${c.contains}"`);
    }
    case 'animates': {
      const a = await page.screenshot({ type: 'png' });
      await page.waitForTimeout(1000);
      const b = await page.screenshot({ type: 'png' });
      const s = await pixelStats(a, b);
      return ok(s.diff > 0.0003, `${(s.diff * 100).toFixed(3)}% pixels changed in 1 s`);
    }
    case 'fps': {
      const fps = await page.evaluate(() => new Promise((res) => {
        let n = 0; const t0 = performance.now();
        const f = (t) => { n++; if (t - t0 < 1500) requestAnimationFrame(f); else res(n / ((t - t0) / 1000)); };
        requestAnimationFrame(f);
      }));
      return ok(fps >= c.min, `${fps.toFixed(1)} fps (min ${c.min})`, Math.min(1, fps / c.min));
    }
    case 'responsive': {
      const r = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, bw: document.body ? document.body.scrollWidth : 0, w: window.innerWidth }));
      const pass = r.sw <= r.w + 1 && r.bw <= r.w + 1;
      return ok(pass, `scrollWidth ${r.sw}/${r.bw} vs ${r.w}`);
    }
    case 'keyboard': {
      const before = await snapshot(page);
      await page.mouse.click(2, 2).catch(() => {});
      for (const k of c.keys) { await page.keyboard.press(k); await page.waitForTimeout(80); }
      await page.waitForTimeout(350);
      const ch = await changed(page, before);
      return ok(ch.changed, `${c.keys.join(',')}: ${ch.detail}`);
    }
    case 'click': {
      const sel = c.sel || 'button';
      const loc = page.locator(sel).first();
      if (await loc.count() === 0) return ok(false, `no ${sel}`);
      const before = await snapshot(page);
      try { await loc.click({ timeout: 3000 }); } catch (e) { return ok(false, 'click failed: ' + e.message.split('\n')[0]); }
      await page.waitForTimeout(450);
      const ch = await changed(page, before);
      return ok(ch.changed, `${sel}: ${ch.detail}`);
    }
    case 'rtl': return ok(await page.evaluate(() => getComputedStyle(document.documentElement).direction === 'rtl'));
    case 'a11y_basic': {
      const issues = await page.evaluate(() => {
        const out = [];
        if (!document.documentElement.getAttribute('lang')) out.push('html lang missing');
        document.querySelectorAll('img').forEach((i) => { if (!i.hasAttribute('alt')) out.push('img without alt'); });
        const named = (el) => (el.getAttribute('aria-label') || '').trim() || el.getAttribute('aria-labelledby') || (el.getAttribute('title') || '').trim();
        document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]), select, textarea').forEach((el) => {
          const lab = (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`)) || el.closest('label');
          if (!lab && !named(el)) out.push(`unlabelled ${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}`);
        });
        document.querySelectorAll('button, [role=button]').forEach((b) => {
          if (!b.textContent.trim() && !named(b) && !b.querySelector('img[alt]:not([alt=""]), svg title')) out.push(`unnamed button${b.id ? '#' + b.id : ''}`);
        });
        return out;
      });
      return ok(issues.length === 0, issues.slice(0, 4).join('; ') || 'ok');
    }
    case 'canvas': {
      const handles = await page.$$('canvas');
      for (const h of handles) {
        const box = await h.boundingBox();
        if (!box || box.width < 2 || box.height < 2) continue;
        const s = await pixelStats(await h.screenshot({ type: 'png' }));
        if (s.distinct > 0.01) return ok(true, `canvas ${Math.round(box.width)}×${Math.round(box.height)}, ${(s.distinct * 100).toFixed(1)}% drawn`);
      }
      return ok(false, `${handles.length} canvas, none drawn`);
    }
    default: return ok(false, 'unknown');
  }
}

async function openPage(browser, url, vp) {
  const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1, hasTouch: false });
  const page = await ctx.newPage();
  const state = { errors: [], loaded: false, loadMs: 0 };
  page.on('pageerror', (e) => state.errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') state.errors.push('console: ' + m.text()); });
  const t0 = Date.now();
  try {
    await page.goto(url, { waitUntil: 'load', timeout: 15000 });
    state.loaded = true;
    state.loadMs = Date.now() - t0;
  } catch (e) { state.errors.push('load: ' + e.message.split('\n')[0]); }
  await page.waitForTimeout(1200);
  return { ctx, page, state };
}

const ORDER = ['loads', 'no_errors', 'nonblank', 'canvas', 'selector', 'text', 'responsive', 'a11y_basic', 'animates', 'fps', 'rtl', 'keyboard', 'click'];
async function runBrowser(browser, url, checks, vp) {
  const { ctx, page, state } = await openPage(browser, url, vp);
  const results = {};
  // Non-mutating checks first, then each mutating check on a fresh page so they don't interfere.
  for (const c of [...checks].sort((a, b) => ORDER.indexOf(a.type) - ORDER.indexOf(b.type))) {
    if (c.type === 'no_errors') continue;
    if (c.type === 'fps' && vp.width < 1000) continue;
    if (['keyboard', 'click'].includes(c.type)) {
      const fresh = await openPage(browser, url, vp);
      results[c.id] = await runBrowserCheck(c, fresh.page, fresh.state);
      state.errors.push(...fresh.state.errors);
      await fresh.ctx.close();
    } else {
      results[c.id] = await runBrowserCheck(c, page, state);
    }
  }
  const ne = checks.find((c) => c.type === 'no_errors');
  if (ne) results[ne.id] = await runBrowserCheck(ne, page, state);
  let shot = null;
  if (SHOTS) shot = await page.screenshot({ type: 'png' });
  await ctx.close();
  return { results, shot, errors: state.errors };
}

function autoScore(checks, staticRes, browserRes) {
  let sw = 0; let s = 0;
  for (const c of checks.static || []) { sw += c.weight; s += c.weight * (staticRes[c.id]?.score ?? 0); }
  for (const c of checks.browser || []) { sw += c.weight; s += c.weight * (browserRes[c.id]?.score ?? 0); }
  return sw ? (100 * s) / sw : 0;
}

// ───────────────────────────── task-specific functional assertions ─────────────────────────────
// Each returns [{name, pass, detail}]. They prove the oracle really implements the prompt.
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const txt = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; }, sel);
const visibleText = (page) => page.evaluate(() => document.body.innerText);
const res = (name, pass, detail = '') => ({ name, pass: !!pass, detail: String(detail).slice(0, 160) });
const focusedId = (page) => page.evaluate(() => document.activeElement ? document.activeElement.id : '');
/* EXTRAS-BEGIN */
const EXTRAS = {
  // ─── ui ───
  'ui-pricing-plans': async (page, { sleep }) => {
    const out = [];
    const t0 = await visibleText(page);
    out.push(res('annual default: $7.50 / $20 / $49.17', /\$7\.50/.test(t0) && /\$20(\.00)?\b/.test(t0) && t0.includes('$49.17')));
    await page.click('#billing-toggle'); await sleep(450);
    const t1 = await visibleText(page);
    out.push(res('monthly: $9 / $24 / $59 + "Billed monthly"', /\$9\b/.test(t1) && /\$24\b/.test(t1) && /\$59\b/.test(t1) && /billed monthly/i.test(t1) && !t1.includes('$49.17')));
    out.push(res('switch state exposed', await page.evaluate(() => { const t = document.querySelector('#billing-toggle'); return t.getAttribute('aria-checked') === 'false' || t.checked === false; })));
    await page.keyboard.press('Tab'); await page.focus('#billing-toggle'); await page.keyboard.press('Space'); await sleep(450);
    out.push(res('keyboard toggles back to annual', (await visibleText(page)).includes('$49.17')));
    out.push(res('exactly 8 comparison rows', (await page.$$('#compare tbody tr')).length === 8));
    const faq = await page.$$('#faq details');
    out.push(res('4 FAQ items', faq.length === 4, faq.length));
    await page.click('#faq summary'); await sleep(200);
    out.push(res('FAQ opens', await page.evaluate(() => document.querySelector('#faq details').open)));
    return out;
  },
  'ui-ops-dashboard': async (page, { sleep }) => {
    const out = [];
    const kpis = await page.$$eval('.kpi', (els) => els.map((e) => e.innerText));
    out.push(res('KPIs 1,142 / 86 / 36.1% / 5', kpis.some((k) => k.includes('1,142')) && kpis.some((k) => /\b86\b/.test(k)) && kpis.some((k) => k.includes('36.1%')) && kpis.some((k) => /needs attention[\s\S]*\b5\b/i.test(k)), kpis.join(' | ')));
    const bars = await page.$$eval('#district-chart .bar', (els) => els.map((e) => +e.getAttribute('width')));
    const sorted = [...bars].sort((a, b) => b - a);
    out.push(res('bar lengths proportional (355:178)', Math.abs(sorted[0] / sorted[3] - 355 / 178) < 0.05, bars.join(',')));
    const firstRow = () => page.$eval('#stations tbody tr', (r) => r.innerText);
    await page.click('#sort-trips'); await sleep(150);
    const a = await firstRow();
    await page.click('#sort-trips'); await sleep(150);
    const b = await firstRow();
    out.push(res('trips sort both directions', (a.includes('University Gardens') && b.includes('Glassworks')) || (b.includes('University Gardens') && a.includes('Glassworks')), a.slice(0, 30) + ' / ' + b.slice(0, 30)));
    out.push(res('aria-sort set', await page.evaluate(() => [...document.querySelectorAll('#stations th[aria-sort]')].some((t) => /ascending|descending/.test(t.getAttribute('aria-sort'))))));
    await page.fill('#search', 'camp'); await sleep(150);
    out.push(res('search "camp" → 3 rows + count line', (await page.$$('#stations tbody tr')).length === 3 && (await visibleText(page)).includes('Showing 3 of 12 stations')));
    await page.fill('#search', 'zzz'); await sleep(150);
    out.push(res('empty state row', /no stations/i.test(await page.$eval('#stations tbody', (t) => t.innerText))));
    await page.fill('#search', ''); await page.click('button[data-status="low"]'); await sleep(150);
    out.push(res('status filter Low → 3 rows', (await page.$$('#stations tbody tr')).length === 3));
    await page.setViewportSize({ width: 390, height: 844 }); await sleep(300);
    await page.click('#menu-toggle'); await sleep(350);
    const open = await page.getAttribute('#menu-toggle', 'aria-expanded');
    await page.keyboard.press('Escape'); await sleep(350);
    out.push(res('mobile drawer opens and closes with Escape', open === 'true' && (await page.getAttribute('#menu-toggle', 'aria-expanded')) === 'false'));
    return out;
  },
  'ui-checkout-flow': async (page, { sleep }) => {
    const out = [];
    await page.click('#next'); await sleep(200);
    const inv = await page.$$eval('[aria-invalid="true"]', (e) => e.length);
    out.push(res('empty step → errors + focus first field', inv >= 6 && (await focusedId(page)) === 'name', `${inv} invalid, focus ${await focusedId(page)}`));
    out.push(res('live region announces', /\d/.test(await page.textContent('#form-status'))));
    await page.fill('#name', 'Ada Lovelace'); await page.fill('#email', 'ada@example'); await page.fill('#address', '12 Analytical Row');
    await page.fill('#city', 'Ljubljana'); await page.fill('#postal', '1000'); await page.selectOption('#country', { index: 3 });
    await page.click('#next'); await sleep(150);
    out.push(res('bad email blocks the step', (await focusedId(page)) === 'email'));
    await page.fill('#email', 'ada@example.com'); await page.click('#next'); await sleep(200);
    out.push(res('step 2 shown, aria-current moved', await page.evaluate(() => document.querySelectorAll('#steps li')[1].getAttribute('aria-current') === 'step')));
    await page.check('input[value=express]'); await sleep(100);
    const tx = await visibleText(page);
    out.push(res('express → €88.80 / VAT €14.80', tx.includes('€88.80') && tx.includes('€14.80')));
    await page.check('input[value=standard]'); await page.click('#next'); await sleep(200);
    await page.click('#card'); await page.keyboard.type('4242424242424241'); await page.fill('#exp', ''); await page.click('#exp'); await page.keyboard.type('1330');
    await page.fill('#cvc', '12'); await page.fill('#cardname', 'A Lovelace'); await page.click('#next'); await sleep(200);
    const errs = await page.$$eval('[aria-invalid="true"]', (e) => e.map((x) => x.id));
    out.push(res('Luhn, month 13 and short CVC rejected', ['card', 'exp', 'cvc'].every((i) => errs.includes(i)), errs.join(',')));
    await page.fill('#card', ''); await page.click('#card'); await page.keyboard.type('4242424242424242');
    out.push(res('card formatted in groups of 4', (await page.inputValue('#card')) === '4242 4242 4242 4242', await page.inputValue('#card')));
    await page.fill('#exp', ''); await page.click('#exp'); await page.keyboard.type('1230');
    await page.fill('#cvc', '123'); await page.click('#next'); await sleep(200);
    out.push(res('review shows masked card', (await visibleText(page)).includes('•••• 4242')));
    await page.click('#next'); await sleep(250);
    out.push(res('confirmation with KL-###### and focus', /KL-\d{6}/.test(await visibleText(page)) && (await page.evaluate(() => document.activeElement.tagName)) === 'H2'));
    return out;
  },
  'ui-command-palette': async (page, { sleep }) => {
    const out = [];
    const firstOpt = () => page.$eval('#palette-results [role=option]', (o) => o.textContent.trim()).catch(() => '');
    await page.focus('#open-palette');
    await page.keyboard.press('/'); await sleep(200);
    out.push(res('"/" opens with input focused', !(await page.$eval('#palette', (p) => p.hidden)) && (await focusedId(page)) === 'palette-input'));
    await page.keyboard.type('gtset'); await sleep(100);
    out.push(res('"gtset" → Go to Settings first', (await firstOpt()).startsWith('Go to Settings'), await firstOpt()));
    out.push(res('matched characters wrapped in <mark>', (await page.$$('#palette-results [role=option] mark')).length > 0));
    await page.fill('#palette-input', 'exp'); await sleep(100);
    out.push(res('"exp" → Export as PDF first', (await firstOpt()).startsWith('Export as PDF'), await firstOpt()));
    await page.fill('#palette-input', 'theme'); await sleep(100);
    out.push(res('"theme" → a Theme-group command first', /theme/i.test(await firstOpt()), await firstOpt()));
    await page.fill('#palette-input', ''); await sleep(100);
    const ad0 = await page.getAttribute('#palette-input', 'aria-activedescendant');
    await page.keyboard.press('ArrowUp'); await sleep(50);
    const adLast = await page.getAttribute('#palette-input', 'aria-activedescendant');
    const lastId = await page.$$eval('#palette-results [role=option]', (o) => o[o.length - 1].id);
    out.push(res('ArrowUp wraps to last option', ad0 !== adLast && adLast === lastId, `${ad0} → ${adLast}`));
    await page.keyboard.press('Tab'); await sleep(50);
    out.push(res('Tab stays in dialog', await page.evaluate(() => document.querySelector('#palette').contains(document.activeElement))));
    await page.fill('#palette-input', 'dark'); await sleep(100);
    await page.keyboard.press('Enter'); await sleep(250);
    out.push(res('Enter runs: dark theme + toast + closed', /dark/.test(await page.evaluate(() => document.documentElement.outerHTML.slice(0, 200) + document.body.className + (document.documentElement.dataset.theme || ''))) && (await page.textContent('#toast')).includes('Ran: Switch to dark theme') && await page.$eval('#palette', (p) => p.hidden)));
    out.push(res('focus returned to invoker', (await focusedId(page)) === 'open-palette', await focusedId(page)));
    await page.keyboard.press('Control+k'); await sleep(200);
    const recentFirst = await firstOpt();
    out.push(res('Ctrl+K opens; Recent group lists last command', recentFirst.startsWith('Switch to dark theme') && /recent/i.test(await page.textContent('#palette-results')), recentFirst));
    await page.keyboard.type('zzqx'); await sleep(100);
    out.push(res('empty state message', /no commands match/i.test(await page.textContent('#palette'))));
    await page.keyboard.press('Escape'); await sleep(150);
    out.push(res('Escape closes', await page.$eval('#palette', (p) => p.hidden)));
    return out;
  },
  'ui-kanban-board': async (page, { sleep }) => {
    const out = [];
    const colOf = (title) => page.evaluate((t) => { const c = [...document.querySelectorAll('.card')].find((x) => x.textContent.includes(t)); return c ? c.closest('.column').querySelector('h2,h3').textContent.trim() : null; }, title);
    const cardSel = (t) => `.card:has-text("${t}")`;
    await page.focus(cardSel('Invoice PDF redesign'));
    await page.keyboard.press('Space'); await sleep(100);
    await page.keyboard.press('ArrowRight'); await sleep(100);
    await page.keyboard.press('ArrowRight'); await sleep(100);
    await page.keyboard.press('Space'); await sleep(300);
    out.push(res('keyboard move Backlog → Review', (await colOf('Invoice PDF redesign')) === 'Review', await colOf('Invoice PDF redesign')));
    out.push(res('announcer mentions Review', /review/i.test(await page.textContent('#announcer'))));
    out.push(res('focus stays on moved card', await page.evaluate(() => document.activeElement.textContent.includes('Invoice PDF redesign'))));
    await page.keyboard.press('Space'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Escape'); await sleep(250);
    out.push(res('Escape cancels a move', (await colOf('Invoice PDF redesign')) === 'Review'));
    await page.focus(cardSel('Dark mode for reports'));
    for (const k of ['Space', 'ArrowRight', 'ArrowRight', 'Space']) { await page.keyboard.press(k); await sleep(80); }
    await sleep(250);
    out.push(res('third card in Review → "Over limit"', (await visibleText(page)).includes('Over limit')));
    // pointer drag (mouse) from Backlog to Done
    const src = await page.locator(cardSel('Audit onboarding emails')).boundingBox();
    const dst = await page.locator('.column').nth(3).boundingBox();
    await page.mouse.move(src.x + src.width / 2, src.y + src.height / 2);
    await page.mouse.down();
    for (let i = 1; i <= 12; i++) { await page.mouse.move(src.x + (dst.x + dst.width / 2 - src.x) * i / 12, src.y + (dst.y + 140 - src.y) * i / 12); await sleep(16); }
    await page.mouse.up(); await sleep(400);
    out.push(res('mouse drag Backlog → Done', (await colOf('Audit onboarding emails')) === 'Done', await colOf('Audit onboarding emails')));
    await page.click('#add-card'); await sleep(150);
    await page.keyboard.press('Enter'); await sleep(150);
    const errShown = (await visibleText(page)).match(/required/i);
    await page.keyboard.type('Ship release notes'); await page.keyboard.press('Enter'); await sleep(300);
    out.push(res('add card validates then adds', !!errShown && (await page.$$('.card')).length === 11 && (await colOf('Ship release notes')) === 'Backlog'));
    await page.click('button[data-who="MK"], .chip:has-text("MK")'); await sleep(150);
    out.push(res('assignee filter dims cards', await page.evaluate(() => [...document.querySelectorAll('.card')].some((c) => parseFloat(getComputedStyle(c).opacity) < 0.6))));
    return out;
  },
  // ─── games ───
  'games-crate-pusher': async (page, { sleep, txt }) => {
    const out = [];
    const K = { u: 'ArrowUp', d: 'ArrowDown', l: 'ArrowLeft', r: 'ArrowRight' };
    const play = async (moves) => { for (const m of moves) { await page.keyboard.press(K[m.toLowerCase()]); await sleep(140); } await sleep(400); };
    await page.keyboard.press('ArrowLeft'); await sleep(200);
    out.push(res('walls block (no move into wall)', (await txt(page, '#moves')) === '0'));
    await page.keyboard.press('ArrowRight'); await sleep(200); await page.keyboard.press('z'); await sleep(200);
    out.push(res('undo returns to start', (await txt(page, '#moves')) === '0'));
    await play('rUluRddRR');
    out.push(res('level 1 solved in 9 moves / 4 pushes', /level complete/i.test(await visibleText(page)) && (await txt(page, '#moves')) === '9' && (await txt(page, '#pushes')) === '4', `${await txt(page, '#moves')}/${await txt(page, '#pushes')}`));
    await page.keyboard.press('Enter'); await sleep(300);
    out.push(res('advances to "Level 2 / 3"', /level 2 \/ 3/i.test(await txt(page, '#level'))));
    await play('uRddrrruuuulllDDDldRRRdrUUUlLDldRRddllUUluuRRRllDldRR');
    out.push(res('level 2 solvable with the reference solution', /level complete/i.test(await visibleText(page))));
    await page.keyboard.press('Enter'); await sleep(300);
    await play('uululDDurrrurrdLLrddlllLrrddrdrUUdllldlluRRluurrrR');
    out.push(res('level 3 → "All levels solved"', /all levels solved/i.test(await visibleText(page))));
    return out;
  },
  'games-falling-blocks': async (page, { sleep, txt }) => {
    const out = [];
    const score = async () => parseInt((await txt(page, '#score')).replace(/\D/g, ''), 10);
    await page.keyboard.press('Space'); await sleep(150);
    const s1 = await score();
    out.push(res('hard drop scores 2 per cell', s1 >= 30 && s1 % 2 === 0, s1));
    await sleep(300);
    await page.keyboard.press('ArrowDown'); await sleep(60);
    out.push(res('soft drop +1', (await score()) === s1 + 1, await score()));
    await page.keyboard.press('p'); await sleep(150);
    const paused = /paused/i.test(await visibleText(page));
    await page.keyboard.press('p'); await sleep(150);
    out.push(res('pause overlay toggles', paused && !/paused/i.test(await visibleText(page))));
    await page.keyboard.press('c'); await sleep(100);
    for (let i = 0; i < 80 && !/game over/i.test(await visibleText(page)); i++) { await page.keyboard.press('Space'); await sleep(40); }
    out.push(res('stack-out → "Game over"', /game over/i.test(await visibleText(page))));
    await page.keyboard.press('Enter'); await sleep(200);
    out.push(res('Enter restarts with score 0', (await score()) === 0 && !/game over/i.test(await visibleText(page))));
    return out;
  },
  'games-tile-platformer': async (page, { sleep }) => {
    const out = [];
    const st = () => page.evaluate(() => window.__wbDebug.state());
    await sleep(400);
    const s0 = await st();
    await page.keyboard.press('ArrowRight'); await sleep(100);
    const s1 = await st();
    out.push(res('a quick tap still moves (input latch)', s1.x > s0.x, `${s0.x.toFixed(1)} → ${s1.x.toFixed(1)}`));
    await page.keyboard.down('ArrowRight'); await sleep(450); await page.keyboard.up('ArrowRight');
    const s2 = await st();
    out.push(res('holding right accelerates to running', s2.x - s1.x > 30, (s2.x - s1.x).toFixed(1)));
    await sleep(500);
    const peak = async (holdMs) => {
      const y0 = (await st()).y; let min = y0;
      await page.keyboard.down('Space');
      const t0 = Date.now();
      let released = false;
      while (Date.now() - t0 < 900) { if (!released && Date.now() - t0 > holdMs) { await page.keyboard.up('Space'); released = true; } min = Math.min(min, (await st()).y); await sleep(10); }
      if (!released) await page.keyboard.up('Space');
      await sleep(300);
      return y0 - min;
    };
    const tall = await peak(700), short = await peak(40);
    out.push(res('jump ≥ 3.5 tiles and variable height', tall >= 56 && short < tall * 0.75, `held ${tall.toFixed(0)} px, tap ${short.toFixed(0)} px`));
    await page.evaluate(() => window.__wbDebug.teleport(36, 9)); await sleep(900);
    out.push(res('spikes cost a life', (await st()).lives === 2));
    await sleep(1400);
    await page.evaluate(() => window.__wbDebug.teleport(74, 11)); await sleep(200);
    await page.keyboard.down('ArrowRight'); await sleep(900); await page.keyboard.up('ArrowRight'); await sleep(200);
    out.push(res('exit → "Level complete"', /level complete/i.test(await visibleText(page)), (await st()).state));
    return out;
  },
  'games-orbital-slingshot': async (page, { sleep }) => {
    const out = [];
    // independent Node simulation of the prompt's physics: every level solvable, direct shots fail
    const LV = [
      { home: { x: 200, y: 700, r: 36 }, bodies: [{ x: 800, y: 450, r: 80, mu: 6e6 }], target: { x: 1350, y: 300, r: 60 } },
      { home: { x: 150, y: 850, r: 36 }, bodies: [{ x: 600, y: 300, r: 60, mu: 5e6 }, { x: 1000, y: 700, r: 90, mu: 9e6 }], target: { x: 1450, y: 150, r: 50 } },
      { home: { x: 150, y: 500, r: 36 }, bodies: [{ x: 800, y: 500, r: 120, mu: 1.6e7 }, { x: 820, y: 140, r: 40, mu: 2.5e6 }, { x: 780, y: 860, r: 45, mu: 2.5e6 }], target: { x: 1450, y: 500, r: 50 } },
    ];
    const sim = (L, deg, v) => {
      const a = deg * Math.PI / 180, DT = 1 / 240;
      let x = L.home.x + Math.cos(a) * (L.home.r + 6), y = L.home.y + Math.sin(a) * (L.home.r + 6), vx = Math.cos(a) * v, vy = Math.sin(a) * v;
      for (let t = 0; t < 20; t += DT) {
        let ax = 0, ay = 0;
        for (const b of L.bodies) { const dx = b.x - x, dy = b.y - y, d2 = dx * dx + dy * dy, d = Math.sqrt(d2), f = b.mu / (d2 + 400); ax += f * dx / d; ay += f * dy / d; }
        vx += ax * DT; vy += ay * DT; x += vx * DT; y += vy * DT;
        if ([...L.bodies, L.home].some((b) => Math.hypot(b.x - x, b.y - y) <= b.r)) return 'crash';
        if (Math.hypot(L.target.x - x, L.target.y - y) <= L.target.r) return 'win';
        if (x < -300 || y < -300 || x > 1900 || y > 1300) return 'lost';
      }
      return 'timeout';
    };
    for (const [i, L] of LV.entries()) {
      let wins = 0;
      for (let deg = 0; deg < 360; deg += 2) for (let v = 60; v <= 420; v += 20) if (sim(L, deg, v) === 'win') wins++;
      const direct = Math.atan2(L.target.y - L.home.y, L.target.x - L.home.x) * 180 / Math.PI;
      const directWins = [200, 300, 420].some((v) => sim(L, direct, v) === 'win');
      out.push(res(`level ${i + 1}: solvable, direct shot fails`, wins > 10 && !directWins, `${wins} winning (angle, speed) pairs`));
    }
    await page.click('#warp'); await sleep(100);
    await page.evaluate(() => window.__wbDebug.aim(0, 260)); await page.keyboard.press('Enter');
    let ok = false;
    for (let i = 0; i < 40 && !ok; i++) { await sleep(200); ok = /target reached/i.test(await visibleText(page)); }
    out.push(res('reference flight matches the Node simulation (L1 @ 0°, 260)', ok));
    out.push(res('attempts counted', /attempts 1/i.test(await visibleText(page))));
    await page.click('#next-level'); await sleep(200);
    out.push(res('next level → "Level 2 / 3"', /level 2 \/ 3/i.test(await visibleText(page))));
    await page.evaluate(() => window.__wbDebug.aim(-30, 120)); await page.keyboard.press('Enter');
    let crashed = false;
    for (let i = 0; i < 40 && !crashed; i++) { await sleep(200); crashed = /crash|lost|time/i.test(await page.textContent('#status')); }
    out.push(res('failed shot reports status', crashed, await page.textContent('#status')));
    return out;
  },
  'games-tower-defense': async (page, { sleep, txt }) => {
    const out = [];
    const st = () => page.evaluate(() => window.__wbDebug.state());
    const placed = await page.evaluate(() => window.__wbDebug.place('arrow', 1, 1));
    out.push(res('cannot build on the path', !placed && (await st()).gold === 150));
    await page.evaluate(() => { window.__wbDebug.place('arrow', 8, 6); window.__wbDebug.select(8, 6); window.__wbDebug.sell(); });
    out.push(res('sell refunds 70% (50 → +35)', (await st()).gold === 135, (await st()).gold));
    // mouse placement through the real UI
    await page.click('#buy-arrow');
    const box = await page.locator('canvas').first().boundingBox();
    await page.mouse.click(box.x + box.width * (8.5 / 16), box.y + box.height * (6.5 / 10)); await sleep(100);
    out.push(res('place via shop + click', (await st()).towers === 1 && (await st()).gold === 85));
    // scripted strategy at 8× time: must reach Victory
    const plan = [['arrow', 4, 6], ['arrow', 6, 7], ['frost', 9, 4], ['arrow', 12, 5], ['arrow', 8, 4], ['frost', 3, 7], ['arrow', 11, 6], ['arrow', 9, 2], ['arrow', 5, 4], ['up', 8, 6], ['up', 4, 6], ['up', 6, 7], ['up', 12, 5], ['up', 8, 4]];
    await page.evaluate(() => window.__wbDebug.timeScale(8));
    let i = 0;
    for (let wave = 0; wave < 6; wave++) {
      await page.evaluate((p) => {
        const d = window.__wbDebug; let k = p.i;
        for (; k < p.plan.length; k++) {
          const [t, x, y] = p.plan[k];
          if (t === 'up') { d.select(x, y); const g = d.state().gold; d.upgrade(); if (d.state().gold === g) break; }
          else if (!d.place(t, x, y)) break;
        }
        window.__planIdx = k;
      }, { plan, i });
      i = await page.evaluate(() => window.__planIdx);
      await page.evaluate(() => window.__wbDebug.startWave());
      for (let k = 0; k < 150; k++) { await sleep(100); const s = await st(); if (!s.waveActive) break; }
      if ((await st()).over) break;
    }
    const fin = await st();
    out.push(res('scripted strategy wins all 6 waves', fin.over && /victory/i.test(await visibleText(page)), JSON.stringify(fin)));
    out.push(res('HUD wave label', /wave 6 \/ 6/i.test(await txt(page, '#wave'))));
    return out;
  },
};
/* EXTRAS-END */

// ───────────────────────────── main ─────────────────────────────
async function main() {
  const tasks = [];
  const structural = [];
  const seen = new Set();
  for (const track of TRACKS) {
    const f = path.join(TASK_DIR, `${track}.json`);
    if (!existsSync(f)) { structural.push(`${track}.json missing`); continue; }
    let arr;
    try { arr = JSON.parse(readFileSync(f, 'utf8')); } catch (e) { structural.push(`${track}.json: ${e.message}`); continue; }
    if (!Array.isArray(arr)) { structural.push(`${track}.json is not an array`); continue; }
    if (arr.length !== 5) structural.push(`${track}.json has ${arr.length} tasks (expected 5)`);
    for (const t of arr) {
      const errs = validateTask(t, track, seen);
      if (errs.length) structural.push(`${t.slug}: ${errs.join('; ')}`);
      tasks.push(t);
    }
  }
  const selected = tasks.filter((t) => !filters.length || filters.includes(t.slug) || filters.includes(t.track));
  let failures = structural.length;
  for (const s of structural) console.log('STRUCT ✗ ' + s);
  if (!structural.length) console.log(`STRUCT ✓ ${tasks.length} tasks valid`);

  const report = [];
  let pw = null; let browser = null; let server = null; let base = '';
  if (!flags['no-browser']) {
    pw = loadPlaywright();
    browser = await pw.chromium.launch({ args: ['--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] });
    const hctx = await browser.newContext();
    helperPage = await hctx.newPage();
    await helperPage.goto('about:blank');
    server = await startServer();
    base = `http://127.0.0.1:${server.address().port}`;
    if (SHOTS) mkdirSync(SHOTS, { recursive: true });
  }

  for (const task of selected) {
    const refPath = path.join(REF_DIR, `${task.slug}.html`);
    const row = { slug: task.slug, problems: [] };
    report.push(row);
    if (!existsSync(refPath)) { row.problems.push('reference missing'); failures++; console.log(`✗ ${task.slug}: reference missing`); continue; }
    const html = readFileSync(refPath, 'utf8');
    if (!html.includes('<!-- WB-ORACLE -->')) row.problems.push('reference lacks <!-- WB-ORACLE -->');
    if (!flags['trivial-only']) {
      // static
      const st = {};
      for (const c of task.checks.static) {
        st[c.id] = runStatic(c, html);
        if (!st[c.id].pass) row.problems.push(`static ${c.id}: ${st[c.id].detail}`);
      }
      row.static = st;
      const php = phpStatic(task, refPath);
      if (php && php.error) row.problems.push('php StaticChecks error: ' + php.error);
      else if (php) {
        row.php = php.results;
        for (const r of php.results) if (r && r.pass === false) row.problems.push(`php static ${r.id}: ${r.detail || ''}`);
      }
    }
    if (browser) {
      const url = `${base}/ref/${task.slug}`;
      if (!flags['trivial-only']) {
        for (const [name, vp] of [['desktop', { width: 1280, height: 800 }], ['mobile', { width: 390, height: 844 }]]) {
          // text checks target the desktop layout (mobile layouts may legitimately collapse panels)
          const list = name === 'mobile' ? task.checks.browser.filter((c) => c.type !== 'text') : task.checks.browser;
          const r = await runBrowser(browser, url, list, vp);
          row[name] = r.results;
          for (const [id, res] of Object.entries(r.results)) if (!res.pass) row.problems.push(`${name} ${id}: ${res.detail}`);
          if (SHOTS && r.shot) writeFileSync(path.join(SHOTS, `${task.slug}-${name}.png`), r.shot);
        }
        row.auto = autoScore(task.checks, row.static, row.desktop);
        const extra = EXTRAS[task.slug];
        if (extra) {
          const { ctx, page, state } = await openPage(browser, url, { width: 1280, height: 800 });
          let res = [];
          try { res = await extra(page, { sleep, txt, pixelStats, snapshot, changed }); } catch (e) { res = [{ name: 'extras crashed', pass: false, detail: e.message.split('\n')[0] }]; }
          if (state.errors.length) res.push({ name: 'no errors during extras', pass: false, detail: state.errors.slice(0, 2).join(' | ') });
          row.extras = res;
          for (const x of res) if (!x.pass) row.problems.push(`extra "${x.name}": ${x.detail || ''}`);
          await ctx.close();
        } else row.problems.push('no functional EXTRAS defined');
      }
      // trivial page must score low
      const tStatic = {};
      for (const c of task.checks.static) tStatic[c.id] = runStatic(c, TRIVIAL);
      const tr = await runBrowser(browser, `${base}/trivial`, task.checks.browser, { width: 1280, height: 800 });
      row.trivial = autoScore(task.checks, tStatic, tr.results);
      if (row.trivial >= TRIVIAL_MAX) row.problems.push(`trivial page scores ${row.trivial.toFixed(1)} (≥ ${TRIVIAL_MAX})`);
    }
    if (row.problems.length) failures++;
    const n = (o) => o ? `${Object.values(o).filter((x) => x.pass).length}/${Object.keys(o).length}` : '-';
    const ex = row.extras ? `${row.extras.filter((x) => x.pass).length}/${row.extras.length}` : '-';
    console.log(`${row.problems.length ? '✗' : '✓'} ${task.slug.padEnd(34)} static ${n(row.static)}  desktop ${n(row.desktop)}  mobile ${n(row.mobile)}  extras ${ex}  auto ${row.auto?.toFixed(1) ?? '-'}  trivial ${row.trivial?.toFixed(1) ?? '-'}`);
    for (const p of row.problems) console.log('     · ' + p);
    if (VERBOSE) {
      for (const k of ['static', 'desktop', 'mobile']) for (const [id, r] of Object.entries(row[k] || {})) console.log(`       ${k} ${id}: ${r.pass ? 'pass' : 'FAIL'} ${r.detail}`);
      for (const x of row.extras || []) console.log(`       extra ${x.name}: ${x.pass ? 'pass' : 'FAIL'} ${x.detail || ''}`);
    }
  }
  if (browser) { await browser.close(); server.close(); }
  if (flags.json) writeFileSync(typeof flags.json === 'string' ? flags.json : path.join(os.tmpdir(), 'validate-visual.json'), JSON.stringify(report, null, 1));
  const trivials = report.filter((r) => r.trivial !== undefined).map((r) => r.trivial);
  if (trivials.length) console.log(`trivial page: mean auto ${(trivials.reduce((a, b) => a + b, 0) / trivials.length).toFixed(1)}, max ${Math.max(...trivials).toFixed(1)}`);
  console.log(failures ? `\n${failures} problem group(s)` : '\nALL GOOD');
  process.exit(failures ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
