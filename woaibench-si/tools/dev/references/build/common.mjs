// DEV ONLY — shared helpers for the task builders in this folder (tasks-code module).
// A builder assembles task objects (prompt text, rubric, checks), computes every expected value by
// running the WB-ORACLE reference, asserts hand-written expectations against it, and writes
// sql/seed/tasks/<track>.json. Re-run:  node tools/dev/references/build/<track>.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import { compare } from '../../validate-tasks.mjs';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
export const REF = (f) => path.join(ROOT, 'tools/dev/references', f);
export const readRef = (slug) => readFileSync(REF(slug + '.js'), 'utf8');

/** Deterministic PRNG source inlined into test scripts (identical results in every JS engine). */
export const LCG = (seed) => `let __s = ${seed >>> 0}; const rnd = () => (__s = (Math.imul(__s, 1664525) + 1013904223) >>> 0) / 4294967296; const ri = (a, b) => a + Math.floor(rnd() * (b - a + 1));`;

/** Same PRNG for builders. */
export function rng(seed) {
  let s = seed >>> 0;
  const rnd = () => (s = (Math.imul(s, 1664525) + 1013904223) >>> 0) / 4294967296;
  return { rnd, ri: (a, b) => a + Math.floor(rnd() * (b - a + 1)), pick: (arr) => arr[Math.floor(rnd() * arr.length)] };
}

function globals() {
  return { console, setTimeout, clearTimeout, queueMicrotask, structuredClone, URL, URLSearchParams, TextEncoder, TextDecoder, atob, btoa };
}

/** Runs one case against code exactly like the harness (fresh context) and returns the value. */
export async function runCode(code, entry, c) {
  const ctx = vm.createContext(globals());
  let v;
  if (typeof c.script === 'string') v = vm.runInContext(code + '\n;(function(){ ' + c.script + ' })()', ctx, { timeout: 20000 });
  else {
    vm.runInContext(code, ctx);
    ctx.__a = JSON.stringify(c.args);
    v = vm.runInContext(`${entry}(...JSON.parse(__a))`, ctx, { timeout: 20000 });
  }
  if (v && typeof v.then === 'function') v = await v;
  // Expectations are stored as JSON, and the browser harness may transport results as JSON too:
  // refuse results that do not survive a JSON round trip unchanged (undefined, NaN, -0, Map, …).
  const round = JSON.parse(JSON.stringify(v === undefined ? null : v));
  if (!strictSame(v, round)) throw new Error(`${c.name}: result is not JSON-stable: ${String(v).slice(0, 80)}`);
  return round;
}

function strictSame(a, b) {
  if (typeof a === 'number' || typeof b === 'number') return Object.is(a, b);
  if (a === b) return true;
  if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  return ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && strictSame(a[k], b[k]));
}

/** Fills `expect` from the reference; when a case already has `expect`, asserts the reference agrees. */
export async function finalizeCases(slug, entry, cases) {
  const code = readRef(slug);
  const out = [];
  for (const c of cases) {
    const got = await runCode(code, entry, c);
    if ('expect' in c) {
      if (!compare(got, c.expect, c.cmp || 'deep', c.tol ?? 1e-6)) {
        throw new Error(`${slug} / ${c.name}: reference returned ${JSON.stringify(got).slice(0, 400)} but hand-written expectation is ${JSON.stringify(c.expect).slice(0, 400)}`);
      }
      out.push(c);
    } else out.push({ ...c, expect: got });
  }
  return out.map(({ name, args, script, expect, cmp, tol }) => {
    const o = { name };
    if (script !== undefined) o.script = script; else o.args = args;
    o.expect = expect;
    if (cmp && cmp !== 'deep') o.cmp = cmp;
    if (tol !== undefined) o.tol = tol;
    return o;
  });
}

/** The output contract appended to every js prompt. */
export function jsContract(entry, kind = 'function') {
  const what = kind === 'class' ? `the class \`${entry}\`` : `\`function ${entry}(...)\``;
  return `## Output format
Reply with exactly one \`\`\`js code block containing the complete solution:
- plain JavaScript (ES2022) that declares ${what} at the top level, plus any helpers it needs;
- no \`import\`, \`export\`, \`require\` or \`module.exports\`; no DOM, Node.js or network APIs; no external libraries;
- the code is evaluated as a classic script inside a Web Worker and called directly by hidden unit tests — do not read input, print output or include your own tests.`;
}

export const STATIC_PARSES = { id: 'parses', type: 'js_parses', weight: 1, label_en: 'Code parses', label_ar: 'الشيفرة سليمة البنية' };
export const STATIC_NO_EVAL = { id: 'no_eval', type: 'not_contains', pattern: '\\beval\\s*\\(', regex: true, weight: 1, label_en: 'No eval()', label_ar: 'لا استخدام لـ eval()' };
export const STATIC_NO_FUNCTION = { id: 'no_function_ctor', type: 'not_contains', pattern: '\\bFunction\\s*\\(', regex: true, weight: 1, label_en: 'No Function constructor', label_ar: 'لا استخدام لمُنشئ Function' };
export const STATIC_NO_INNERHTML = { id: 'no_innerhtml', type: 'not_contains', pattern: 'innerHTML', weight: 1, label_en: 'No innerHTML', label_ar: 'لا استخدام لـ innerHTML' };

/** Rubric criteria (judges score code quality; correctness is measured by the tests). */
export const R = {
  readability: (w = 2) => ({ key: 'readability', label_en: 'Readability & structure', label_ar: 'المقروئية والبنية', weight: w,
    guide: 'Clear names, small focused helpers, no dead code, comments only where the logic is non-obvious. 9–10: a reviewer understands every step at first read.', guide_ar: 'أسماء واضحة ودوال مساعدة صغيرة مركّزة، بلا شيفرة ميتة، وتعليقات حيث يكون المنطق غير بديهي فقط. 9–10: يفهم المراجع كل خطوة من القراءة الأولى.' }),
  robustness: (w = 3) => ({ key: 'robustness', label_en: 'Robustness & edge cases', label_ar: 'المتانة والحالات الحدّية', weight: w,
    guide: 'Handles every edge case named in the contract (empty input, boundaries, duplicates, large values) deliberately rather than by accident; never mutates inputs; fails in the documented way.', guide_ar: 'يعالج كل حالة حدّية يذكرها العقد (الإدخال الفارغ والحدود والتكرارات والقيم الكبيرة) عن قصد لا مصادفةً؛ لا يعدّل المدخلات أبدًا، ويفشل بالطريقة الموثّقة.' }),
  complexity: (w = 3) => ({ key: 'complexity', label_en: 'Algorithmic efficiency', label_ar: 'الكفاءة الخوارزمية', weight: w,
    guide: 'Meets the stated complexity bound with an appropriate data structure; no hidden quadratic steps (repeated array scans, splice/shift in loops, re-sorting per query); reasonable memory.', guide_ar: 'يحقق حدّ التعقيد المطلوب ببنية بيانات مناسبة؛ بلا خطوات تربيعية خفية (مسح متكرر للمصفوفات، أو splice/shift داخل الحلقات، أو إعادة الفرز مع كل استعلام)، وباستهلاك معقول للذاكرة.' }),
  idiomatic: (w = 2) => ({ key: 'idiomatic', label_en: 'Idiomatic modern JavaScript', label_ar: 'جافاسكربت حديثة واصطلاحية', weight: w,
    guide: 'Uses const/let, strict equality, appropriate built-ins and typed arrays where useful; avoids global state, implicit coercion and needless cleverness.', guide_ar: 'يستخدم const/let والمساواة الصارمة والدوال المضمّنة المناسبة والمصفوفات المنمّطة عند الحاجة، ويتجنب الحالة العامة والتحويل الضمني للأنواع والتذاكي غير الضروري.' }),
  diagnosis: (w = 3) => ({ key: 'diagnosis', label_en: 'Root-cause fixes', label_ar: 'إصلاح السبب الجذري', weight: w,
    guide: 'Each reported symptom is fixed at its root cause (not patched for the example input); unrelated behaviour and the public signature are preserved; no new bugs introduced.', guide_ar: 'يُصلح كل عَرَض مُبلَّغ عنه من سببه الجذري لا بترقيع المثال المذكور؛ ويحافظ على السلوك غير المعني وعلى توقيع الدالة العام، ولا يُدخل أخطاء جديدة.' }),
  minimal: (w = 2) => ({ key: 'minimal_diff', label_en: 'Focused, reviewable change', label_ar: 'تعديل مركّز قابل للمراجعة', weight: w,
    guide: 'The fix keeps the original structure where it was sound, so a reviewer can map each change to a reported bug; brief comments explain non-obvious fixes.', guide_ar: 'يُبقي الإصلاح على البنية الأصلية حيث كانت سليمة، بحيث يستطيع المراجع ربط كل تعديل بخطأ مُبلَّغ عنه، مع تعليقات موجزة تشرح الإصلاحات غير البديهية.' }),
  security: (w = 4) => ({ key: 'security', label_en: 'Security reasoning', label_ar: 'التفكير الأمني', weight: w,
    guide: 'Defends by construction (allow-lists, canonicalise-then-check, single decoding, own-property access) rather than by block-lists of known payloads; no eval/Function/innerHTML; fails closed.', guide_ar: 'يدافع بالتصميم (قوائم سماح، وتوحيد الصيغة قبل التحقق، وفك ترميز مرة واحدة، والاقتصار على الخصائص الذاتية) لا بقوائم حظر لحمولات معروفة؛ بلا eval أو Function أو innerHTML، ويرفض عند الشك.' }),
};

/** Writes a track file with stable 2-space JSON. */
export function writeTrack(track, tasks) {
  const file = path.join(ROOT, 'sql/seed/tasks', track + '.json');
  writeFileSync(file, JSON.stringify(tasks, null, 2) + '\n');
  console.log(`wrote ${file} (${tasks.length} tasks)`);
}

export function assert(cond, msg) {
  if (!cond) throw new Error('assertion failed: ' + msg);
}
