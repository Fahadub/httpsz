#!/usr/bin/env node
// WOAIBench.si — DEV ONLY task validator (never deployed; tools/ is blocked by .htaccess).
//
//   node tools/dev/validate-tasks.mjs [track ...] [--task=slug] [--no-mutants] [--no-tz] [--verbose] [--json]
//
// For every task in sql/seed/tasks/*.json (optionally filtered by track / slug):
//   1. structural validation (required fields, unique slugs / ids / case names, known check types,
//      cmp modes, rubric shape, bilingual labels, prompt mentions the contract);
//   2. js tasks: runs tools/dev/references/<slug>.js (must contain "// WB-ORACLE") against every test
//      case with the harness semantics of ARCHITECTURE §5.1 (see runCase below), asserts 100 % pass and
//      that the slowest case uses < 25 % of timeout_ms; re-runs the reference under several time zones;
//      runs each mutant tools/dev/references/mutants/<slug>.<name>.js and asserts it FAILS ≥ 1 case;
//   3. answer tasks: checks tools/dev/references/<slug>.answer.txt is accepted by the answer spec
//      (JS re-implementation of AnswerCheck, plus app/eval/AnswerCheck.php through the php CLI when it
//      exists), runs <slug>.verify.mjs (independent computation) and compares, checks every line of
//      mutants/<slug>.wrong.txt is REJECTED and every line of mutants/<slug>.right.txt is ACCEPTED.
// Exit code 0 only when everything passes.

import { Worker, isMainThread, parentPort, workerData } from 'node:worker_threads';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const __filename = fileURLToPath(import.meta.url);
const ROOT = path.resolve(path.dirname(__filename), '../..');
const REF_DIR = path.join(ROOT, 'tools/dev/references');
const MUT_DIR = path.join(REF_DIR, 'mutants');

const STATIC_TYPES = ['contains', 'not_contains', 'selector', 'max_bytes', 'min_bytes', 'no_external', 'viewport',
  'lang_attr', 'dir_rtl', 'html_parses', 'single_file', 'regex_count', 'js_parses'];
const BROWSER_TYPES = ['loads', 'no_errors', 'nonblank', 'selector', 'text', 'animates', 'fps', 'responsive',
  'keyboard', 'click', 'rtl', 'a11y_basic', 'canvas'];
const CMP_MODES = ['deep', 'approx', 'set', 'sorted', 'truthy'];
const ANSWER_TYPES = ['exact', 'number', 'regex', 'set', 'json', 'one_of'];
const OUTPUT_TYPES = ['html', 'js', 'answer', 'agent', 'text'];
const TZ_SWEEP = ['America/Los_Angeles', 'Asia/Kolkata', 'Pacific/Kiritimati'];
const REF_BUDGET = 0.25; // reference must finish every case within 25 % of timeout_ms

// ───────────────────────────── comparison semantics (shared with the browser harness) ─────────────
function canon(v) {
  if (v === undefined) return 'u';
  if (typeof v === 'number') return Number.isNaN(v) ? 'NaN' : (Object.is(v, -0) ? '0' : String(v));
  if (v === null || typeof v !== 'object') return JSON.stringify(v) ?? String(v);
  if (Array.isArray(v)) return '[' + v.map(canon).join(',') + ']';
  return '{' + Object.keys(v).filter((k) => v[k] !== undefined).sort().map((k) => JSON.stringify(k) + ':' + canon(v[k])).join(',') + '}';
}

function deepEq(a, b, numEq) {
  if (typeof a === 'number' && typeof b === 'number') return numEq(a, b);
  if (a === b) return true;
  if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') return false;
  const aa = Array.isArray(a);
  if (aa !== Array.isArray(b)) return false;
  if (aa) {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) if (!deepEq(a[i], b[i], numEq)) return false;
    return true;
  }
  const ka = Object.keys(a).filter((k) => a[k] !== undefined);
  const kb = Object.keys(b).filter((k) => b[k] !== undefined);
  if (ka.length !== kb.length) return false;
  for (const k of ka) {
    if (!Object.prototype.hasOwnProperty.call(b, k) || !deepEq(a[k], b[k], numEq)) return false;
  }
  return true;
}

const exactNum = (a, b) => a === b || (Number.isNaN(a) && Number.isNaN(b));

/** cmp semantics: deep | approx (absolute tol, default 1e-6) | set / sorted (order-insensitive multiset) | truthy. */
export function compare(got, expect, cmp = 'deep', tol = 1e-6) {
  switch (cmp) {
    case 'approx':
      return deepEq(got, expect, (a, b) => exactNum(a, b) || Math.abs(a - b) <= tol);
    case 'set':
    case 'sorted': {
      if (!Array.isArray(got) || !Array.isArray(expect) || got.length !== expect.length) return false;
      const g = got.map(canon).sort();
      const e = expect.map(canon).sort();
      return g.every((x, i) => x === e[i]);
    }
    case 'truthy':
      return Boolean(got) === Boolean(expect === undefined ? true : expect);
    default:
      return deepEq(got, expect, exactNum);
  }
}

function preview(v) {
  let s;
  try {
    s = typeof v === 'string' ? JSON.stringify(v) : (v === undefined ? 'undefined' : JSON.stringify(v, (k, x) => (typeof x === 'number' && !Number.isFinite(x) ? String(x) : (typeof x === 'bigint' ? x + 'n' : x))));
  } catch (e) {
    s = String(v);
  }
  if (s === undefined) s = String(v);
  return s.length > 300 ? s.slice(0, 297) + '...' : s;
}

// ───────────────────────────── worker: executes one solution against a suite ──────────────────────
function makeGlobals() {
  const quiet = { log() {}, info() {}, warn() {}, error() {}, debug() {}, table() {}, dir() {}, trace() {} };
  return {
    console: quiet, setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask, structuredClone,
    URL, URLSearchParams, TextEncoder, TextDecoder, atob, btoa, performance,
    crypto: globalThis.crypto,
  };
}

async function runCase(code, entry, c, timeoutMs) {
  const ctx = vm.createContext(makeGlobals());
  const opts = { timeout: timeoutMs, filename: 'solution.js' };
  const t0 = performance.now();
  let value;
  try {
    if (typeof c.script === 'string') {
      value = vm.runInContext(code + '\n;(function(){ ' + c.script + ' })()', ctx, opts);
    } else {
      vm.runInContext(code, ctx, opts);
      ctx.__wb_args_json = JSON.stringify(c.args ?? []);
      value = vm.runInContext('(function(){ const f = (typeof ' + entry + " === 'function') ? " + entry +
        " : undefined; if (!f) throw new ReferenceError('entry function " + entry + " not defined'); " +
        'return f(...JSON.parse(globalThis.__wb_args_json)); })()', ctx, opts);
    }
    if (value && typeof value.then === 'function') {
      const left = Math.max(1, timeoutMs - (performance.now() - t0));
      let timer;
      value = await Promise.race([
        Promise.resolve(value),
        new Promise((_, rej) => { timer = setTimeout(() => rej(new Error('timeout (async)')), left); }),
      ]).finally(() => clearTimeout(timer));
    }
  } catch (e) {
    const ms = performance.now() - t0;
    const msg = e && e.code === 'ERR_SCRIPT_EXECUTION_TIMEOUT' ? 'timeout' : ((e && e.name ? e.name + ': ' : '') + (e && e.message !== undefined ? e.message : String(e)));
    return { name: c.name, pass: false, ms, error: msg };
  }
  const ms = performance.now() - t0;
  const pass = compare(value, c.expect, c.cmp || 'deep', c.tol ?? 1e-6) && ms <= timeoutMs;
  const res = { name: c.name, pass, ms };
  if (!pass) res.got = preview(value);
  if (ms > timeoutMs) res.error = 'timeout';
  return res;
}

if (!isMainThread) {
  const { code, entry, cases, timeoutMs, start } = workerData;
  (async () => {
    for (let i = start; i < cases.length; i++) {
      parentPort.postMessage({ type: 'start', i });
      const r = await runCase(code, entry, cases[i], timeoutMs);
      parentPort.postMessage({ type: 'result', i, r });
    }
    parentPort.postMessage({ type: 'done' });
  })();
}

/** Runs all cases of a suite in worker threads with a hard watchdog (timeout + grace) per case. */
function runSuite(code, tests) {
  const cases = tests.cases;
  const timeoutMs = tests.timeout_ms || 2000;
  const results = new Array(cases.length);
  return new Promise((resolve) => {
    const spawn = (start) => {
      if (start >= cases.length) return resolve(results);
      const w = new Worker(__filename, { workerData: { code, entry: tests.entry, cases, timeoutMs, start } });
      let current = start;
      let timer = null;
      let finished = false;
      const arm = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
          finished = true;
          results[current] = { name: cases[current].name, pass: false, ms: timeoutMs + 1500, error: 'timeout (watchdog)' };
          w.terminate().then(() => spawn(current + 1));
        }, timeoutMs + 1500);
      };
      w.on('message', (m) => {
        if (m.type === 'start') { current = m.i; arm(); }
        else if (m.type === 'result') { results[m.i] = m.r; clearTimeout(timer); }
        else if (m.type === 'done') { finished = true; clearTimeout(timer); w.terminate(); resolve(results); }
      });
      w.on('error', (e) => {
        if (finished) return;
        finished = true;
        clearTimeout(timer);
        results[current] = { name: cases[current].name, pass: false, ms: 0, error: 'worker crash: ' + (e && e.message) };
        spawn(current + 1);
      });
    };
    spawn(0);
  });
}

// ───────────────────────────── AnswerCheck (JS re-implementation of §5.1) ──────────────────────────
/** Returns the content of the LAST <answer>…</answer> block, trimmed, or null. */
export function extractAnswer(text) {
  const re = /<answer>([\s\S]*?)<\/answer>/gi;
  let m;
  let last = null;
  while ((m = re.exec(String(text))) !== null) last = m[1];
  return last === null ? null : last.trim();
}

const normSpace = (s) => String(s).replace(/\s+/gu, ' ').trim();
function parseNumber(s) {
  const t = String(s).replace(/[\s,  _]/gu, '');
  if (!/^[+-]?(\d+(\.\d*)?|\.\d+)(e[+-]?\d+)?$/i.test(t)) return null;
  return Number(t);
}

/** Checks a full model response against an answer spec. */
export function checkAnswer(spec, response) {
  const a = extractAnswer(response);
  if (a === null) return false;
  const ci = Boolean(spec.ci);
  const fold = (s) => (ci ? normSpace(s).toLowerCase() : normSpace(s));
  switch (spec.type) {
    case 'exact':
      return fold(a) === fold(spec.value);
    case 'number': {
      const n = parseNumber(a);
      return n !== null && Math.abs(n - Number(spec.value)) <= Number(spec.tolerance ?? 0) + 1e-12;
    }
    case 'regex':
      return new RegExp(spec.pattern, 'u').test(a);
    case 'set': {
      const items = (s) => [...new Set(String(s).split(/[,\n]/u).map(fold).filter((x) => x !== ''))].sort();
      const got = items(a);
      const want = items(spec.value.join(','));
      return got.length === want.length && got.every((x, i) => x === want[i]);
    }
    case 'json': {
      try { return deepEq(JSON.parse(a), spec.value, exactNum); } catch (e) { return false; }
    }
    case 'one_of':
      return spec.values.some((v) => fold(v) === fold(a));
    default:
      return false;
  }
}

// ───────────────────────────── structure validation ───────────────────────────────────────────────
const AR = /[؀-ۿ]/u;
function validateStructure(task, tracks, errs) {
  const need = ['slug', 'track', 'title_en', 'title_ar', 'summary_en', 'summary_ar', 'prompt', 'output_type', 'difficulty', 'visibility'];
  for (const k of need) if (task[k] === undefined || task[k] === '') errs.push(`missing field ${k}`);
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(task.slug || '') || (task.slug || '').length > 120) errs.push('slug must be kebab-case ≤ 120 chars');
  if (!tracks.has(task.track)) errs.push(`unknown track ${task.track}`);
  if (!OUTPUT_TYPES.includes(task.output_type)) errs.push(`unknown output_type ${task.output_type}`);
  if (!Number.isInteger(task.difficulty) || task.difficulty < 1 || task.difficulty > 4) errs.push('difficulty must be 1..4');
  if (!['public', 'holdout'].includes(task.visibility)) errs.push('visibility must be public|holdout');
  if (task.auto_weight !== null && task.auto_weight !== undefined && !(task.auto_weight >= 0 && task.auto_weight <= 1)) errs.push('auto_weight must be null or 0..1');
  for (const k of ['title_ar', 'summary_ar']) if (task[k] && !AR.test(task[k])) errs.push(`${k} has no Arabic text`);
  if ((task.title_en || '').length > 200 || (task.title_ar || '').length > 200) errs.push('title longer than 200 chars');
  const rubric = task.rubric ?? [];
  if (!Array.isArray(rubric)) errs.push('rubric must be an array');
  const rkeys = new Set();
  for (const c of Array.isArray(rubric) ? rubric : []) {
    for (const k of ['key', 'label_en', 'label_ar', 'weight', 'guide']) if (c[k] === undefined || c[k] === '') errs.push(`rubric criterion missing ${k}`);
    if (rkeys.has(c.key)) errs.push(`duplicate rubric key ${c.key}`);
    rkeys.add(c.key);
    if (!(c.weight > 0)) errs.push(`rubric ${c.key} weight must be > 0`);
    if (c.label_ar && !AR.test(c.label_ar)) errs.push(`rubric ${c.key} label_ar has no Arabic`);
  }
  const checks = task.checks ?? {};
  const ids = new Set();
  for (const group of ['static', 'browser']) {
    for (const c of checks[group] ?? []) {
      const known = group === 'static' ? STATIC_TYPES : BROWSER_TYPES;
      if (!known.includes(c.type)) errs.push(`unknown ${group} check type ${c.type}`);
      if (!c.id || ids.has(c.id)) errs.push(`missing/duplicate check id ${c.id}`);
      ids.add(c.id);
      if (!c.label_en || !c.label_ar || !AR.test(c.label_ar)) errs.push(`check ${c.id} needs label_en + Arabic label_ar`);
      if ((c.type === 'contains' || c.type === 'not_contains' || c.type === 'regex_count') && typeof c.pattern !== 'string') errs.push(`check ${c.id} needs pattern`);
      if (c.regex) { try { new RegExp(c.pattern, c.ci ? 'iu' : 'u'); } catch (e) { errs.push(`check ${c.id} bad regex: ${e.message}`); } }
    }
  }
  if (task.output_type === 'js') {
    const t = checks.tests;
    if (!t || typeof t.entry !== 'string' || !Array.isArray(t.cases)) errs.push('js task needs checks.tests {entry, cases}');
    else {
      if (!/^[A-Za-z_$][\w$]*$/.test(t.entry)) errs.push('tests.entry must be an identifier');
      if (!(t.timeout_ms >= 100 && t.timeout_ms <= 15000)) errs.push('tests.timeout_ms must be 100..15000');
      if (t.cases.length < 15 || t.cases.length > 40) errs.push(`expected 15..40 cases, got ${t.cases.length}`);
      const names = new Set();
      for (const c of t.cases) {
        if (!c.name || names.has(c.name)) errs.push(`missing/duplicate case name ${c.name}`);
        names.add(c.name);
        if (c.cmp && !CMP_MODES.includes(c.cmp)) errs.push(`case ${c.name}: unknown cmp ${c.cmp}`);
        if (typeof c.script !== 'string' && !Array.isArray(c.args)) errs.push(`case ${c.name}: needs args[] or script`);
        if (!('expect' in c)) errs.push(`case ${c.name}: missing expect`);
      }
      if (!task.prompt.includes(t.entry)) errs.push(`prompt never mentions entry ${t.entry}`);
    }
    if (!(checks.static ?? []).some((c) => c.type === 'js_parses')) errs.push('js task should have a js_parses static check');
  }
  if (task.output_type === 'answer') {
    const a = checks.answer;
    if (!a || !ANSWER_TYPES.includes(a.type)) errs.push('answer task needs checks.answer with a known type');
    if (!/<answer>/.test(task.prompt)) errs.push('prompt must ask for <answer></answer>');
    if (rubric.length) errs.push('answer tasks must not have a rubric');
    if (a && a.type === 'number' && !(typeof a.value === 'number' && a.tolerance >= 0)) errs.push('number answer needs value + tolerance');
    if (a && a.type === 'regex') { try { new RegExp(a.pattern, 'u'); } catch (e) { errs.push('bad answer regex'); } }
  }
}

/** Static checks the reference itself must satisfy (contains / not_contains / js_parses / bytes). */
function staticPass(code, c) {
  const test = () => (c.regex ? new RegExp(c.pattern, c.ci ? 'iu' : 'u').test(code) : (c.ci ? code.toLowerCase().includes(c.pattern.toLowerCase()) : code.includes(c.pattern)));
  switch (c.type) {
    case 'contains': return test();
    case 'not_contains': return !test();
    case 'js_parses': try { new vm.Script(code); return true; } catch (e) { return false; }
    case 'max_bytes': return Buffer.byteLength(code) <= c.value;
    case 'min_bytes': return Buffer.byteLength(code) >= c.value;
    default: return true;
  }
}

// ───────────────────────────── PHP AnswerCheck bridge (optional) ───────────────────────────────────
function phpAnswerCheck(spec, response) {
  const file = path.join(ROOT, 'app/eval/AnswerCheck.php');
  if (!existsSync(file)) return null;
  const php = `define('WB', true); define('WB_ROOT', ${JSON.stringify(ROOT)}); require WB_ROOT . '/app/eval/AnswerCheck.php';
$in = json_decode(stream_get_contents(STDIN), true);
$cls = 'WB\\\\Eval\\\\AnswerCheck';
foreach (['check', 'evaluate', 'score'] as $m) { if (method_exists($cls, $m)) { $r = $cls::$m($in['spec'], $in['text']); break; } }
if (!isset($r)) { echo 'nomethod'; exit; }
if (is_array($r)) { $r = $r['pass'] ?? $r['ok'] ?? (($r['score'] ?? 0) >= 1); } elseif (is_numeric($r)) { $r = $r >= 1 || $r >= 100; }
echo $r ? 'pass' : 'fail';`;
  const out = spawnSync('php', ['-d', 'display_errors=stderr', '-r', php], { input: JSON.stringify({ spec, text: response }), encoding: 'utf8' });
  const s = (out.stdout || '').trim();
  if (s === 'pass') return true;
  if (s === 'fail') return false;
  return 'error: ' + (s || out.stderr || '').slice(0, 200);
}

// ───────────────────────────── main ───────────────────────────────────────────────────────────────
async function main() {
  const argv = process.argv.slice(2);
  const flags = new Set(argv.filter((a) => a.startsWith('--')).map((a) => a.split('=')[0]));
  const taskFilter = (argv.find((a) => a.startsWith('--task=')) || '').slice(7);
  const trackFilter = argv.filter((a) => !a.startsWith('--'));
  const refOnly = flags.has('--ref-only');
  const verbose = flags.has('--verbose');
  const asJson = flags.has('--json');
  const log = (...a) => { if (!asJson) console.log(...a); };

  const tracks = new Set(JSON.parse(readFileSync(path.join(ROOT, 'sql/seed/tracks.json'), 'utf8')).map((t) => t.slug));
  const taskDir = path.join(ROOT, 'sql/seed/tasks');
  const all = [];
  for (const f of readdirSync(taskDir).filter((f) => f.endsWith('.json')).sort()) {
    const list = JSON.parse(readFileSync(path.join(taskDir, f), 'utf8'));
    if (!Array.isArray(list)) throw new Error(`${f}: expected an array of tasks`);
    for (const t of list) all.push({ ...t, __file: f });
  }
  const seen = new Map();
  let failures = 0;
  const report = [];
  for (const t of all) {
    if (seen.has(t.slug)) { console.error(`DUPLICATE slug ${t.slug} in ${t.__file} and ${seen.get(t.slug)}`); failures++; }
    seen.set(t.slug, t.__file);
  }
  const tasks = all.filter((t) => (!trackFilter.length || trackFilter.includes(t.track)) && (!taskFilter || t.slug === taskFilter));
  if (!tasks.length) { console.error('no tasks matched'); process.exit(1); }

  for (const task of tasks) {
    const errs = [];
    const row = { slug: task.slug, track: task.track, type: task.output_type };
    validateStructure(task, tracks, errs);
    if (task.output_type === 'js' && task.checks?.tests && !errs.length) {
      const refFile = path.join(REF_DIR, task.slug + '.js');
      if (!existsSync(refFile)) errs.push('missing reference ' + refFile);
      else {
        const code = readFileSync(refFile, 'utf8');
        if (!code.includes('// WB-ORACLE')) errs.push('reference lacks // WB-ORACLE marker');
        for (const c of task.checks.static ?? []) if (!staticPass(code, c)) errs.push(`reference fails static check ${c.id}`);
        const res = await runSuite(code, task.checks.tests);
        const failed = res.filter((r) => !r.pass);
        const maxMs = Math.max(...res.map((r) => r.ms));
        const slowest = res.reduce((a, b) => (b.ms > a.ms ? b : a));
        row.cases = res.length;
        row.ref_total_ms = Math.round(res.reduce((s, r) => s + r.ms, 0));
        row.ref_max_ms = Math.round(maxMs);
        row.timeout_ms = task.checks.tests.timeout_ms;
        row.slowest = slowest.name;
        for (const f of failed) errs.push(`reference FAILS ${f.name}: ${f.error || 'got ' + f.got}`);
        if (maxMs > REF_BUDGET * task.checks.tests.timeout_ms) errs.push(`reference too slow: ${slowest.name} ${maxMs.toFixed(0)} ms > ${REF_BUDGET * 100}% of ${task.checks.tests.timeout_ms}`);
        if (verbose) for (const r of res) log(`    ${r.pass ? 'ok  ' : 'FAIL'} ${r.ms.toFixed(1).padStart(8)} ms  ${r.name}${r.pass ? '' : '  ' + (r.error || r.got)}`);
        if (!refOnly && !flags.has('--no-mutants')) {
          const muts = existsSync(MUT_DIR) ? readdirSync(MUT_DIR).filter((f) => f.startsWith(task.slug + '.') && f.endsWith('.js')).sort() : [];
          row.mutants = muts.length;
          row.killed = 0;
          row.mutant_detail = [];
          for (const m of muts) {
            const mres = await runSuite(readFileSync(path.join(MUT_DIR, m), 'utf8'), task.checks.tests);
            const k = mres.filter((r) => !r.pass);
            if (k.length) row.killed++;
            else errs.push(`mutant SURVIVED: ${m}`);
            row.mutant_detail.push(`${m.slice(task.slug.length + 1, -3)}: ${k.length}/${mres.length} fail` + (verbose ? ' [' + k.map((r) => r.name).join(', ') + ']' : ''));
          }
          if (muts.length < 2) errs.push(`only ${muts.length} mutants (need ≥ 2)`);
        }
      }
    }
    if (task.output_type === 'answer' && task.checks?.answer && !errs.length) {
      const spec = task.checks.answer;
      const ansFile = path.join(REF_DIR, task.slug + '.answer.txt');
      if (!existsSync(ansFile)) errs.push('missing ' + ansFile);
      else {
        const ans = readFileSync(ansFile, 'utf8').trim();
        row.answer = ans.length > 40 ? ans.slice(0, 37) + '...' : ans;
        const resp = `Reasoning...\n<answer>${ans}</answer>`;
        if (!checkAnswer(spec, resp)) errs.push('reference answer rejected by spec (JS AnswerCheck)');
        const php = phpAnswerCheck(spec, resp);
        row.php = php === null ? 'n/a' : (php === true ? 'pass' : String(php));
        if (php !== null && php !== true) errs.push('reference answer not accepted by AnswerCheck.php: ' + php);
        const verify = path.join(REF_DIR, task.slug + '.verify.mjs');
        if (existsSync(verify)) {
          const out = spawnSync(process.execPath, [verify], { encoding: 'utf8', timeout: 120000 });
          const v = (out.stdout || '').trim().split('\n').pop();
          row.verify = v === ans ? 'match' : `MISMATCH (${v})`;
          if (v !== ans) errs.push(`verify script printed "${v}" but answer.txt is "${ans}"`);
        } else errs.push('missing verify script ' + verify);
        if (!refOnly) {
          const lines = (f) => (existsSync(f) ? readFileSync(f, 'utf8').split('\n').map((s) => s.replace(/\r$/, '')).filter((s) => s.trim() !== '') : []);
          const wrong = lines(path.join(MUT_DIR, task.slug + '.wrong.txt'));
          const right = lines(path.join(MUT_DIR, task.slug + '.right.txt'));
          row.mutants = wrong.length;
          row.killed = 0;
          for (const w of wrong) {
            const r = `<answer>${w}</answer>`;
            const js = checkAnswer(spec, r);
            const ph = phpAnswerCheck(spec, r);
            if (!js && (ph === null || ph === false)) row.killed++;
            else errs.push(`wrong answer ACCEPTED: ${w} (js=${js}, php=${ph})`);
          }
          for (const ok of right) {
            const r = ok.includes('<answer>') ? ok.replace(/\\n/g, '\n') : `<answer>${ok}</answer>`;
            const ph = phpAnswerCheck(spec, r);
            if (!checkAnswer(spec, r) || (ph !== null && ph !== true)) errs.push(`acceptable variant REJECTED: ${ok} (php=${ph})`);
          }
          row.variants_ok = right.length;
          if (wrong.length < 2) errs.push('need ≥ 2 wrong answers in mutants/<slug>.wrong.txt');
        }
      }
    }
    row.ok = errs.length === 0;
    row.errors = errs;
    if (errs.length) failures++;
    report.push(row);
    if (!asJson) {
      const head = `${row.ok ? 'PASS' : 'FAIL'}  ${task.track.padEnd(9)} ${task.slug}`;
      const det = task.output_type === 'js'
        ? `cases=${row.cases ?? '-'} ref=${row.ref_total_ms ?? '-'}ms (max ${row.ref_max_ms ?? '-'}ms / ${row.timeout_ms ?? '-'}ms)` + (row.mutants !== undefined ? ` mutants killed=${row.killed}/${row.mutants}` : '')
        : `answer=${row.answer ?? '-'} verify=${row.verify ?? '-'} php=${row.php ?? '-'}` + (row.mutants !== undefined ? ` wrong rejected=${row.killed}/${row.mutants} variants=${row.variants_ok}` : '');
      log(head.padEnd(48) + det);
      if (row.mutant_detail && (verbose || !row.ok)) for (const d of row.mutant_detail) log('        ' + d);
      for (const e of errs) log('        ✗ ' + e);
    }
  }

  // Re-run js references under other time zones (results must not depend on the local zone).
  if (!refOnly && !flags.has('--no-tz') && tasks.some((t) => t.output_type === 'js')) {
    for (const tz of TZ_SWEEP) {
      const args = [__filename, ...trackFilter, '--ref-only', '--json'];
      if (taskFilter) args.push('--task=' + taskFilter);
      const out = spawnSync(process.execPath, args, { encoding: 'utf8', env: { ...process.env, TZ: tz }, timeout: 600000 });
      let rows = [];
      try { rows = JSON.parse(out.stdout); } catch (e) { rows = [{ slug: '(all)', ok: false, errors: ['tz run crashed: ' + (out.stderr || '').slice(0, 300)] }]; }
      const bad = rows.filter((r) => r.type !== 'answer' && !r.ok);
      log(`TZ ${tz.padEnd(20)} ${bad.length ? 'FAIL' : 'ok'}`);
      for (const b of bad) { failures++; log(`        ✗ ${b.slug}: ${b.errors.join('; ')}`); }
    }
  }
  if (asJson) console.log(JSON.stringify(report));
  else log(failures ? `\n${failures} task(s) with problems` : `\nAll ${tasks.length} task(s) valid.`);
  process.exit(failures ? 1 : 0);
}

const isEntry = process.argv[1] && path.resolve(process.argv[1]) === __filename;
if (isMainThread && isEntry) main().catch((e) => { console.error(e); process.exit(2); });
