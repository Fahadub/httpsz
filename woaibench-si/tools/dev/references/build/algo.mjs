// DEV ONLY — builds sql/seed/tasks/algo.json.   node tools/dev/references/build/algo.mjs
import vm from 'node:vm';
import { readRef, finalizeCases, jsContract, STATIC_PARSES, R, writeTrack, LCG, rng, assert } from './common.mjs';
import { studioBrute, transitBrute, editDistanceDP, rankBrute, sheetBrute } from './oracles-algo.mjs';
import { compare } from '../../validate-tasks.mjs';

const load = (slug, entry) => {
  const ctx = vm.createContext({});
  vm.runInContext(readRef(slug), ctx);
  return (...args) => JSON.parse(JSON.stringify(ctx[entry](...JSON.parse(JSON.stringify(args)))));
};
const JS = (v) => JSON.stringify(v);

// ════════════════════════════════════════════ 1. studio allocator ════════════════════════════════
async function studioTask() {
  const slug = 'algo-studio-allocator';
  const ref = load(slug, 'allocateStudios');
  const g = rng(101);
  const randomCase = (n, m, maxT) => {
    const caps = Array.from({ length: m }, () => g.ri(1, 6));
    const bookings = Array.from({ length: n }, () => { const s = g.ri(0, maxT); return { start: s, end: s + g.ri(1, 15), size: g.ri(1, 7) }; });
    return [caps, bookings, g.ri(0, 4)];
  };
  // cross-check reference vs brute force
  for (let k = 0; k < 3000; k++) {
    const args = randomCase(g.ri(0, 30), g.ri(1, 6), g.ri(0, 60));
    assert(JS(ref(...args)) === JS(studioBrute(...args)), 'studio cross-check ' + JS(args));
  }
  const cases = [
    { name: 'example_from_prompt', args: [[4, 2, 8], [{ start: 0, end: 10, size: 2 }, { start: 5, end: 20, size: 3 }, { start: 12, end: 14, size: 1 }, { start: 15, end: 30, size: 2 }, { start: 15, end: 16, size: 5 }], 5], expect: [1, 0, 2, 1, -1] },
    { name: 'no_bookings', args: [[3, 1], [], 10], expect: [] },
    { name: 'single_room_single_booking', args: [[1], [{ start: 0, end: 1, size: 1 }], 0], expect: [0] },
    { name: 'equal_start_keeps_input_order', args: [[1], [{ start: 0, end: 5, size: 1 }, { start: 0, end: 5, size: 1 }], 0], expect: [0, -1] },
    { name: 'processed_by_start_not_input_order', args: [[1], [{ start: 10, end: 20, size: 1 }, { start: 0, end: 15, size: 1 }], 0], expect: [-1, 0] },
    { name: 'turnaround_boundary_inclusive', args: [[3], [{ start: 0, end: 10, size: 1 }, { start: 15, end: 20, size: 1 }], 5], expect: [0, 0] },
    { name: 'turnaround_one_minute_short', args: [[3], [{ start: 0, end: 10, size: 1 }, { start: 14, end: 20, size: 1 }], 5], expect: [0, -1] },
    { name: 'zero_turnaround_back_to_back', args: [[2], [{ start: 0, end: 10, size: 2 }, { start: 10, end: 20, size: 2 }, { start: 20, end: 30, size: 2 }], 0], expect: [0, 0, 0] },
    { name: 'best_fit_beats_lower_index', args: [[10, 2], [{ start: 0, end: 5, size: 2 }], 0], expect: [1] },
    { name: 'capacity_tie_lowest_index', args: [[5, 3, 3, 5], [{ start: 0, end: 5, size: 2 }, { start: 0, end: 5, size: 2 }, { start: 0, end: 5, size: 4 }, { start: 0, end: 5, size: 4 }, { start: 0, end: 5, size: 1 }], 0], expect: [1, 2, 0, 3, -1] },
    { name: 'exact_capacity_fits', args: [[3, 7], [{ start: 0, end: 1, size: 7 }, { start: 0, end: 1, size: 3 }], 0], expect: [1, 0] },
    { name: 'too_large_for_every_room', args: [[3, 7], [{ start: 0, end: 1, size: 8 }], 0], expect: [-1] },
    { name: 'rejection_reserves_nothing', args: [[4, 2], [{ start: 0, end: 10, size: 3 }, { start: 0, end: 10, size: 3 }, { start: 5, end: 10, size: 2 }], 0], expect: [0, -1, 1] },
    { name: 'freed_small_room_preferred', args: [[2, 9], [{ start: 0, end: 10, size: 1 }, { start: 3, end: 6, size: 1 }, { start: 12, end: 13, size: 1 }], 2], expect: [0, 1, 0] },
    { name: 'big_times_and_turnaround', args: [[1, 1], [{ start: 999000000, end: 999999000, size: 1 }, { start: 999999999, end: 1000000000, size: 1 }, { start: 1000000, end: 998999999, size: 1 }], 1000000], expect: [1, 0, 0] },
    { name: 'room_reused_many_times', args: [[1], Array.from({ length: 12 }, (_, i) => ({ start: i * 3, end: i * 3 + 2, size: 1 })), 1], expect: Array(12).fill(0) },
  ];
  for (let k = 1; k <= 8; k++) {
    const args = randomCase(k < 5 ? 25 : 40, k < 5 ? 3 : 6, k < 5 ? 40 : 80);
    cases.push({ name: `random_small_${k}`, args, expect: studioBrute(...args) });
  }
  cases.push({ name: 'does_not_mutate_inputs', script: `const caps = [5, 1, 3, 3]; const bk = [{start: 9, end: 12, size: 3}, {start: 1, end: 4, size: 1}, {start: 1, end: 2, size: 4}]; const before = JSON.stringify([caps, bk]); const r = allocateStudios(caps, bk, 1); return [JSON.stringify([caps, bk]) === before, r];`, expect: [true, [2, 1, 0]] });
  const checksum = `let h = 0, rej = 0; for (let j = 0; j < r.length; j++) { if (r[j] === -1) rej++; h = (h * 31 + r[j] + 2) % 1000000007; } return { n: r.length, rejected: rej, checksum: h, head: r.slice(0, 6) };`;
  const perfRandom = `${LCG(7)} const m = 100000, n = 100000; const caps = []; for (let i = 0; i < m; i++) caps.push(ri(1, 50)); const bk = []; for (let j = 0; j < n; j++) { const s = ri(0, 1000000); bk.push({ start: s, end: s + ri(1, 20000), size: ri(1, 50) }); } const r = allocateStudios(caps, bk, 30); ${checksum}`;
  const perfStack = `const m = 100000, n = 100000; const caps = new Array(m).fill(1); const bk = []; for (let j = 0; j < n; j++) bk.push({ start: j, end: 1000000000, size: 1 }); const r = allocateStudios(caps, bk, 0); let ok = true; for (let j = 0; j < n; j++) if (r[j] !== j) { ok = false; break; } return ok;`;
  const perfChurn = `${LCG(11)} const m = 2000, n = 100000; const caps = []; for (let i = 0; i < m; i++) caps.push(ri(1, 100)); const bk = []; for (let j = 0; j < n; j++) { const s = ri(0, 5000000); bk.push({ start: s, end: s + ri(1, 300000), size: ri(1, 100) }); } const r = allocateStudios(caps, bk, 500); ${checksum}`;
  const perfWide = `${LCG(13)} const m = 100000, n = 100000; const caps = []; for (let i = 0; i < m; i++) caps.push(ri(1, 1000000)); const bk = []; for (let j = 0; j < n; j++) { const s = ri(0, 100000000); bk.push({ start: s, end: s + ri(1, 50000000), size: ri(500000, 1000000) }); } const r = allocateStudios(caps, bk, 1000); ${checksum}`;
  cases.push({ name: 'perf_random_100k', script: perfRandom });
  cases.push({ name: 'perf_all_overlapping_100k', script: perfStack, expect: true });
  cases.push({ name: 'perf_churn_with_rejections', script: perfChurn });
  cases.push({ name: 'perf_large_bookings_wide_capacities', script: perfWide });
  // cross-check the perf generators at reduced size against the brute force
  for (const [src, a, b] of [[perfRandom, 'm = 100000, n = 100000', 'm = 1500, n = 1500'], [perfChurn, 'm = 2000, n = 100000', 'm = 300, n = 3000'], [perfWide, 'm = 100000, n = 100000', 'm = 1200, n = 1200']]) {
    const small = src.replace(a, b);
    const run = (code) => { const c = vm.createContext({}); return JS(vm.runInContext(code + '\n;(function(){' + small + '})()', c)); };
    const bruteCode = `function allocateStudios(c, b, t) { return (${studioBrute.toString()})(c, b, t); }`;
    assert(run(readRef(slug)) === run(bruteCode), 'studio perf generator cross-check');
  }

  return {
    slug, track: 'algo',
    title_en: 'Studio Booking Allocator', title_ar: 'مُخصِّص حجوزات الاستوديوهات',
    summary_en: 'Assign recording-studio bookings to rooms by best-fit capacity with a turnaround buffer between sessions — 100 000 bookings, O(n log n).',
    summary_ar: 'توزيع حجوزات استوديوهات التسجيل على الغرف وفق أفضل سعة ملائمة مع فاصل تجهيز بين الجلسات — مئة ألف حجز بتعقيد O(n log n).',
    prompt: `# Studio Booking Allocator

A music school rents out recording studios. Booking requests arrive as a batch and must be assigned automatically: each booking needs one room that is available for its slot and has enough seats, and after every session the room needs a fixed turnaround time (cleaning, re-patching cables) before the next group can enter.

Implement \`function allocateStudios(capacities, bookings, turnaround)\`.

## Input
- \`capacities\` — array of \`m\` integers (1 ≤ m ≤ 100 000, each between 1 and 1 000 000). Room \`i\` has \`capacities[i]\` seats.
- \`bookings\` — array of \`n\` objects \`{ start, end, size }\` (0 ≤ n ≤ 100 000). All values are integers (minutes) with \`0 ≤ start < end ≤ 1 000 000 000\` and \`1 ≤ size ≤ 1 000 000\`.
- \`turnaround\` — integer, 0 ≤ turnaround ≤ 1 000 000.

## Rules
1. Bookings are processed in order of increasing \`start\`; bookings with the same \`start\` are processed in their original array order.
2. Every room is available initially. A room assigned to a booking that ends at \`end\` becomes available again for bookings whose \`start ≥ end + turnaround\`.
3. A booking may only use a room that is available at its \`start\` and has \`capacity ≥ size\`. Among those rooms it takes the one with the **smallest capacity**; ties go to the **smallest room index**.
4. If no such room exists the booking is rejected and reserves nothing.

## Return value
An array of length \`n\` whose element \`j\` is the room index assigned to \`bookings[j]\`, or \`-1\` if that booking was rejected (the result follows the original booking order). Do not modify \`capacities\` or \`bookings\`.

## Performance
Hidden tests use n = m = 100 000 with adversarial patterns (for example every booking overlapping every other one). A full call must finish in well under a second in a browser, so aim for O((n + m) · log(n + m)); scanning the list of rooms for every booking is too slow.

## Example
\`\`\`js
allocateStudios([4, 2, 8], [
  { start: 0,  end: 10, size: 2 },
  { start: 5,  end: 20, size: 3 },
  { start: 12, end: 14, size: 1 },
  { start: 15, end: 30, size: 2 },
  { start: 15, end: 16, size: 5 },
], 5);
// → [1, 0, 2, 1, -1]
// Booking 3 starts exactly when room 1 is available again (10 + 5 = 15).
// Booking 4 needs 5 seats: room 2 is busy until 19 and room 0 is too small.
\`\`\`

${jsContract('allocateStudios')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: [R.complexity(4), R.robustness(3), R.readability(2), R.idiomatic(1)],
    checks: { static: [STATIC_PARSES], tests: { entry: 'allocateStudios', timeout_ms: 4000, weight: 9, cases: await finalizeCases(slug, 'allocateStudios', cases) } },
  };
}

// ════════════════════════════════════════════ 2. spreadsheet engine ══════════════════════════════
function randomSheet(g) {
  const cols = ['A', 'B', 'C'];
  const rows = 4;
  const names = [];
  for (const c of cols) for (let r = 1; r <= rows; r++) if (g.rnd() < 0.8) names.push(c + r);
  const anyRef = () => g.pick(cols) + g.ri(1, rows + 1);
  const expr = (d) => {
    const r = g.rnd();
    if (d > 2 || r < 0.25) return String(g.pick([0, 1, 2, 3, 0.5, 10]));
    if (r < 0.5) return g.rnd() < 0.5 ? anyRef() : anyRef().toLowerCase();
    if (r < 0.62) return '-' + expr(d + 1);
    if (r < 0.72) return '(' + expr(d + 1) + ')';
    if (r < 0.85) {
      const f = g.pick(['SUM', 'MIN', 'MAX', 'ABS', 'sum']);
      if (f === 'ABS') return 'ABS(' + expr(d + 1) + ')';
      const args = [];
      const k = g.ri(1, 3);
      for (let i = 0; i < k; i++) args.push(g.rnd() < 0.5 ? anyRef() + ':' + anyRef() : expr(d + 1));
      return f + '(' + args.join(', ') + ')';
    }
    return expr(d + 1) + ' ' + g.pick(['+', '-', '*', '/', '^']) + ' ' + expr(d + 1);
  };
  const cells = {};
  for (const n of names) {
    const r = g.rnd();
    if (r < 0.3) cells[n] = String(g.pick([1, 2, -3, 0, 4.5, ' 7 ']));
    else if (r < 0.37) cells[n] = g.pick(['txt', '', '#REF!']);
    else if (r < 0.42) cells[n] = '=' + g.pick(['1+', '(2', 'FOO(1)', 'A1:A2', 'ABS(1,2)']);
    else cells[n] = '=' + expr(0);
  }
  return cells;
}

async function sheetTask() {
  const slug = 'algo-sheet-evaluator';
  const ref = load(slug, 'evaluateSheet');
  const g = rng(202);
  for (let k = 0; k < 6000; k++) {
    const s = randomSheet(g);
    const a = ref(s);
    const b = JSON.parse(JSON.stringify(sheetBrute(s)));
    assert(compare(a, b, 'approx', 1e-9), 'sheet cross-check ' + JS(s) + '\nref ' + JS(a) + '\nbrute ' + JS(b));
  }
  const cases = [
    { name: 'basic_arithmetic', args: [{ A1: '2', A2: '3', A3: '=A1*A2+1' }], expect: { A1: 2, A2: 3, A3: 7 } },
    { name: 'empty_sheet', args: [{}], expect: {} },
    { name: 'precedence_and_associativity', args: [{ A1: '=2+3*4^2', A2: '=(2+3)*4', A3: '=2^3^2', A4: '=-2^2', A5: '=2^-1', A6: '=--3', A7: '=10/4/5', A8: '=10-4-3', A9: '=-SUM(1,2)^2', A10: '=2*-3' }],
      expect: { A1: 50, A2: 20, A3: 512, A4: -4, A5: 0.5, A6: 3, A7: 0.5, A8: 3, A9: -9, A10: -6 } },
    { name: 'literal_cells', args: [{ A1: ' 3.50 ', A2: '-7', A3: '1e3', A4: 'hello', A5: '', A6: '007', A7: '.5', A8: '+2', A9: '1.' }],
      expect: { A1: 3.5, A2: -7, A3: '1e3', A4: 'hello', A5: '', A6: 7, A7: 0.5, A8: 2, A9: 1 } },
    { name: 'number_literal_forms_in_formula', args: [{ A1: '=1.+.5', A2: '=0010.250*4', A3: '=  7  ' }], expect: { A1: 1.5, A2: 41, A3: 7 } },
    { name: 'text_through_formulas', args: [{ A1: 'hello', B1: '=A1', C1: '=A1+1', D1: '=-A1', E1: '=(A1)', F1: '=B1' }],
      expect: { A1: 'hello', B1: 'hello', C1: '#VALUE!', D1: '#VALUE!', E1: 'hello', F1: 'hello' } },
    { name: 'missing_reference', args: [{ A1: '=B7+1', A2: '=A1*2', A3: '=SUM(A1, 1)' }], expect: { A1: '#REF!', A2: '#REF!', A3: '#REF!' } },
    { name: 'division_by_zero', args: [{ A1: '=1/0', A2: '=0/0', A3: '=5/(2-2)', A4: '=A1+1', A5: '=0/5' }], expect: { A1: '#DIV/0!', A2: '#DIV/0!', A3: '#DIV/0!', A4: '#DIV/0!', A5: 0 } },
    { name: 'non_finite_results', args: [{ A1: '=10^400', A2: '=(-8)^(1/3)', A3: '=0^-1', A4: '=1.5^2', A5: '=A1*0' }], expect: { A1: '#NUM!', A2: '#NUM!', A3: '#NUM!', A4: 2.25, A5: '#NUM!' } },
    { name: 'parse_errors', args: [{ A1: '=', A2: '=1+', A3: '=(1', A4: '=1)', A5: '=A0', A6: '=A01', A7: '=FOO(1)', A8: '=SUM()', A9: '=ABS(1,2)', A10: '=1 2', A11: '=A2:A3', A12: '=ABS(B1:B2)', A13: '=AAAA1', A14: '=3%', A15: '=SUM(A1,)', A16: '=1..2', A17: '=A1B2' }],
      expect: Object.fromEntries(Array.from({ length: 17 }, (_, i) => ['A' + (i + 1), '#PARSE!'])) },
    { name: 'case_insensitive_and_whitespace', args: [{ A1: '4', B2: '= sum( a1 , 6 ) * abs( -2 )', C3: '=\tmax(a1,b2)\t', D4: '=Min(A1:c3)' }], expect: { A1: 4, B2: 20, C3: 20, D4: 4 } },
    { name: 'ranges_skip_text_and_missing', args: [{ A1: '1', B1: '2', C1: 'x', A2: '4', C2: '6', A3: '7', B3: '8', C3: '9', D1: '=SUM(A1:C3)', D2: '=SUM(C3:A1)', D3: '=MIN(B1:C2)', D4: '=MAX(A1:A3, 100)', D5: '=SUM(E1:E9)', D6: '=MIN(C1:C1)', D7: '=MAX(A3:C1)' }],
      expect: { A1: 1, B1: 2, C1: 'x', A2: 4, C2: 6, A3: 7, B3: 8, C3: 9, D1: 37, D2: 37, D3: 2, D4: 100, D5: 0, D6: 0, D7: 9 } },
    { name: 'errors_inside_ranges_and_args', args: [{ A1: '1', A2: '=1/0', A3: '3', B1: '=SUM(A1:A3)', B2: '=SUM(A1, B9)', B3: '=MAX(A1:A3)', B4: '=SUM(Q9, 1/0)', B5: '=MIN(A3, A2:A2)' }],
      expect: { A1: 1, A2: '#DIV/0!', A3: 3, B1: '#DIV/0!', B2: '#REF!', B3: '#DIV/0!', B4: '#REF!', B5: '#DIV/0!' } },
    { name: 'direct_text_argument', args: [{ A1: 't', B1: '=SUM(A1)', B2: '=SUM(A1:A1)', B3: '=ABS(A1)', B4: '=MAX(A1:A1)' }], expect: { A1: 't', B1: '#VALUE!', B2: 0, B3: '#VALUE!', B4: 0 } },
    { name: 'self_reference_cycle', args: [{ A1: '=A1+1', B1: '5' }], expect: { A1: '#CYCLE!', B1: 5 } },
    { name: 'cycle_members_and_dependents', args: [{ A1: '=B1', B1: '=A1', C1: '=A1+1', D1: '=1/0+A1', E1: '=X9+A1', F1: '=SUM(A1:B1)', G1: '=C1*0' }],
      expect: { A1: '#CYCLE!', B1: '#CYCLE!', C1: '#CYCLE!', D1: '#DIV/0!', E1: '#REF!', F1: '#CYCLE!', G1: '#CYCLE!' } },
    { name: 'range_includes_itself', args: [{ A1: '1', A2: '2', A3: '=SUM(A1:A3)', B1: '=A3*2', B2: '=A1+A2' }], expect: { A1: 1, A2: 2, A3: '#CYCLE!', B1: '#CYCLE!', B2: 3 } },
    { name: 'parse_error_breaks_would_be_cycle', args: [{ A1: '=B1+', B1: '=A1', C1: '=B1*2' }], expect: { A1: '#PARSE!', B1: '#PARSE!', C1: '#PARSE!' } },
    { name: 'three_cycle_with_tail', args: [{ A1: '=B1+1', B1: '=C1+1', C1: '=A1+1', D1: '=C1', E1: '7', F1: '=E1*E1' }], expect: { A1: '#CYCLE!', B1: '#CYCLE!', C1: '#CYCLE!', D1: '#CYCLE!', E1: 7, F1: 49 } },
    { name: 'first_error_wins_left_to_right', args: [{ A1: '=1/0', B1: '=X1', C1: '=A1+B1', D1: '=B1+A1', E1: '=-A1*B1', F1: '=txt', G1: 'txt', H1: '=G1+1/0', I1: '=G1*X5' }],
      expect: { A1: '#DIV/0!', B1: '#REF!', C1: '#DIV/0!', D1: '#REF!', E1: '#DIV/0!', F1: '#PARSE!', G1: 'txt', H1: '#DIV/0!', I1: '#REF!' } },
    { name: 'error_looking_text_is_text', args: [{ A1: '#DIV/0!', B1: '=A1', C1: '=A1+1' }], expect: { A1: '#DIV/0!', B1: '#DIV/0!', C1: '#VALUE!' } },
    { name: 'multi_letter_columns', args: [{ Z1: '1', AA1: '2', AB1: '3', AC1: '=SUM(Z1:AB1)', ZZ9: '=AC1*10', AAA10: '=ZZ9+aa1' }], expect: { Z1: 1, AA1: 2, AB1: 3, AC1: 6, ZZ9: 60, AAA10: 62 } },
    { name: 'floating_point_values', args: [{ A1: '0.1', A2: '0.2', A3: '=A1+A2', A4: '=1/3*3', A5: '=SUM(A1:A2)*10', A6: '=2^0.5' }], cmp: 'approx', tol: 1e-9, expect: { A1: 0.1, A2: 0.2, A3: 0.3, A4: 1, A5: 3, A6: Math.SQRT2 } },
    { name: 'deep_parentheses', args: [{ A1: '=' + '('.repeat(100) + '1+1' + ')'.repeat(100) }], expect: { A1: 2 } },
  ];
  const rawRef = (() => { const cx = vm.createContext({}); vm.runInContext(readRef(slug), cx); return cx.evaluateSheet; })();
  for (let k = 1; k <= 4; k++) {
    let s;
    do { s = randomSheet(g); } while (Object.values(rawRef(s)).some((v) => Object.is(v, -0))); // keep results JSON-stable
    cases.push({ name: `random_sheet_${k}`, args: [s], cmp: 'approx', tol: 1e-9, expect: JSON.parse(JS(sheetBrute(s))) });
  }
  cases.push({ name: 'does_not_mutate_input', script: `const c = { A1: '=B1*2', B1: '21' }; const before = JSON.stringify(c); const r = evaluateSheet(c); return [JSON.stringify(c) === before, r.A1];`, expect: [true, 42] });
  cases.push({ name: 'chain_50000_forward', script: `const c = { A1: '1' }; for (let i = 2; i <= 50000; i++) c['A' + i] = '=A' + (i - 1) + '+1'; const r = evaluateSheet(c); return [r.A50000, r.A25000, r.A1];`, expect: [50000, 25000, 1] });
  cases.push({ name: 'chain_50000_reverse_key_order', script: `const c = {}; for (let i = 50000; i >= 2; i--) c['B' + i] = '=b' + (i - 1) + '*1+1'; c.B1 = '0'; const r = evaluateSheet(c); return [r.B50000, r.B2];`, expect: [49999, 1] });
  cases.push({ name: 'cycle_of_30000_cells', script: `const c = {}; for (let i = 1; i <= 30000; i++) c['C' + i] = '=C' + (i === 30000 ? 1 : i + 1); c.D1 = '=C500+1'; c.D2 = '=SUM(C1:C3, 1/0)'; const r = evaluateSheet(c); let n = 0; for (const k in r) if (r[k] === '#CYCLE!') n++; return [n, r.D2];`, expect: [30002, '#CYCLE!'] });
  cases.push({ name: 'perf_20000_range_formulas', script: `${LCG(5)} const c = {}; for (let r = 1; r <= 20000; r++) { c['A' + r] = String(ri(0, 99)); c['B' + r] = String(ri(0, 99)); c['C' + r] = String(ri(0, 99)); c['D' + r] = '=SUM(A' + r + ':C' + r + ')*2-MAX(A' + r + ':B' + r + ')'; } const res = evaluateSheet(c); let s = 0; for (let r = 1; r <= 20000; r++) s += res['D' + r]; return s;` });
  cases.push({ name: 'perf_dense_grid_dependencies', script: `const c = {}; const L = (n) => { let s = ''; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = (n - 1 - m) / 26; } return s; }; for (let col = 1; col <= 100; col++) for (let row = 1; row <= 300; row++) { const name = L(col) + row; c[name] = row === 1 ? String(col) : '=' + L(col) + (row - 1) + (col > 1 ? '+' + L(col - 1) + (row - 1) : '') + '-' + L(col) + '1'; } const r = evaluateSheet(c); return [r.CV300, r.A300, r.B2];` });

  for (const [name, a, b] of [['perf_20000_range_formulas', /20000/g, '300'], ['perf_dense_grid_dependencies', /col <= 100; col\+\+\) for \(let row = 1; row <= 300/, 'col <= 8; col++) for (let row = 1; row <= 12']]) {
    const c = cases.find((x) => x.name === name);
    const small = c.script.replace(a, b).replace('return [r.CV300, r.A300, r.B2];', 'return r;').replace(/const res = evaluateSheet\(c\);.*$/, 'return evaluateSheet(c);');
    const run = (code) => { const cx = vm.createContext({}); return JSON.parse(JS(vm.runInContext(code + '\n;(function(){' + small + '})()', cx))); };
    const sheet = run('function evaluateSheet(c) { return c; }');
    assert(Object.keys(sheet).length > 20 && compare(run(readRef(slug)), JSON.parse(JS(sheetBrute(sheet))), 'approx', 1e-9), 'sheet perf cross-check ' + name);
  }
  return {
    slug, track: 'algo',
    title_en: 'Spreadsheet Formula Engine', title_ar: 'محرّك صيغ الجداول الحسابية',
    summary_en: 'Parse and evaluate a sheet of formulas with ranges, error propagation and cycle detection — including 50 000-cell dependency chains.',
    summary_ar: 'تحليل صيغ ورقة حسابية وتقييمها مع النطاقات وانتشار الأخطاء واكتشاف الحلقات، بما في ذلك سلاسل اعتماد تبلغ خمسين ألف خلية.',
    prompt: `# Spreadsheet Formula Engine

Implement the calculation core of a small web spreadsheet: \`function evaluateSheet(cells)\`.

## Input
\`cells\` is a plain object mapping cell names to raw strings, e.g. \`{ "A1": "2", "B1": "=A1*3" }\` (at most 100 000 cells).
- A **cell name** is 1–3 uppercase letters (column) followed by a row number from 1 to 999 999 without leading zeros: \`A1\`, \`AB12\`, \`ZZZ999999\`. Columns are numbered A = 1 … Z = 26, AA = 27, AB = 28, …
- A raw string that starts with \`=\` is a **formula** (the rest of the string is the expression).
- Any other raw string is a **literal**. If it matches \`^\\s*[+-]?(\\d+(\\.\\d*)?|\\.\\d+)\\s*$\` it is the number it denotes (\`" 3.50 "\` → 3.5, \`"007"\` → 7, \`"1."\` → 1); otherwise it is **text** and its value is the raw string itself (\`"1e3"\`, \`""\` and \`"#DIV/0!"\` are text).

## Formula grammar
Tokens may be separated by spaces or tabs. Cell references and function names are case-insensitive (\`a1\` = \`A1\`, \`sum\` = \`SUM\`).
\`\`\`
expr    := term (("+" | "-") term)*
term    := unary (("*" | "/") unary)*
unary   := ("+" | "-") unary | power
power   := primary ("^" unary)?          // right-associative; binds tighter than unary minus
primary := number | cellref | func "(" arg ("," arg)* ")" | "(" expr ")"
arg     := cellref ":" cellref | expr     // a range is only allowed as a function argument
number  := digits ["." digits?] | "." digits      (no exponent: 12, 1.5, 1., .5)
func    := SUM | MIN | MAX | ABS
\`\`\`
So \`-2^2\` = −4, \`2^-1\` = 0.5, \`2^3^2\` = 512, \`10-4-3\` = 3. \`ABS\` takes exactly one non-range argument; \`SUM\`, \`MIN\`, \`MAX\` take one or more arguments. A range \`X:Y\` is the rectangle spanned by the two references (either corner order), visited row by row from top to bottom and left to right within a row. Formulas are at most 2 000 characters with parentheses nested at most 100 deep; a range covers at most 10 000 cells.

## Values and errors
A cell value is a finite number, a text string, or one of these error codes (strings): \`#PARSE!\`, \`#REF!\`, \`#DIV/0!\`, \`#VALUE!\`, \`#NUM!\`, \`#CYCLE!\`.
- \`#PARSE!\` — the formula does not match the grammar (including an unknown function, wrong \`ABS\` arity, a stray range, a reference such as \`A0\`, \`A01\` or \`AAAA1\`).
- \`#CYCLE!\` — the cell lies on a dependency cycle. A cell depends on every existing cell it references directly or through a range (a formula with \`#PARSE!\` has no dependencies). Every cell on a cycle (including a cell that depends on itself) gets \`#CYCLE!\`; other cells are evaluated normally and may receive \`#CYCLE!\` only through error propagation.
- A direct reference to a cell that does not exist is \`#REF!\`. Inside a range, missing cells are skipped.
- A reference to a text cell yields the text (so \`=A1\` can be text). Using text as an operand of \`+ - * / ^\`, of unary \`+\`/\`-\`, or as a direct function argument gives \`#VALUE!\`. Text cells inside a range are skipped.
- Division by zero gives \`#DIV/0!\`. Any other operation whose result is not a finite number (overflow, \`(-8)^(1/3)\`, \`0^-1\`) gives \`#NUM!\`.
- **Error propagation:** a binary operator evaluates its left operand, then its right operand; the first of them that is an error is the result. Only then are text operands rejected with \`#VALUE!\`. Functions evaluate their arguments left to right (range cells in the visiting order above) and return the first error they meet.
- \`SUM\` adds the collected numbers (0 if none). \`MIN\` / \`MAX\` return 0 when no numbers were collected. \`ABS\` is the absolute value.

## Return value
A new plain object with exactly the same keys whose values are the evaluated cell values (numbers, text strings or error codes). Do not modify the input.

## Performance & robustness
Hidden tests include dependency chains of 50 000 cells (\`A2 = A1+1\`, \`A3 = A2+1\`, …), a cycle of 30 000 cells and 20 000 formulas with ranges. Each call must finish in about a second, so total work must be roughly linear in the size of the sheet — and must not overflow the call stack on long chains (browser stacks hold only ~10 000 frames).

## Example
\`\`\`js
evaluateSheet({ A1: "2", A2: "=A1^3", A3: "=SUM(A1:A2, -1)", B1: "=B2", B2: "=B1", C1: "=X9+1" });
// → { A1: 2, A2: 8, A3: 9, B1: "#CYCLE!", B2: "#CYCLE!", C1: "#REF!" }
\`\`\`

${jsContract('evaluateSheet')}`,
    output_type: 'js', difficulty: 4, visibility: 'public', auto_weight: null,
    rubric: [R.robustness(3), R.complexity(3), R.readability(3), R.idiomatic(1)],
    checks: { static: [STATIC_PARSES], tests: { entry: 'evaluateSheet', timeout_ms: 5000, weight: 9, cases: await finalizeCases(slug, 'evaluateSheet', cases) } },
  };
}

// ════════════════════════════════════════════ 3. transit windows ═════════════════════════════════
async function transitTask() {
  const slug = 'algo-transit-windows';
  const ref = load(slug, 'earliestArrival');
  const g = rng(303);
  const randomNet = (n, L) => {
    const links = [];
    for (let i = 0; i < L; i++) {
      const w = [];
      const k = g.ri(0, 3);
      for (let j = 0; j < k; j++) { const o = g.ri(0, 60); w.push([o, o + g.ri(0, 12)]); }
      links.push({ from: g.ri(0, n - 1), to: g.ri(0, n - 1), duration: g.ri(1, 15), windows: w });
    }
    return [n, links, g.ri(0, n - 1), g.ri(0, n - 1), g.ri(0, 30)];
  };
  for (let k = 0; k < 6000; k++) {
    const a = randomNet(g.ri(1, 8), g.ri(0, 20));
    assert(ref(...a) === transitBrute(...a), 'transit cross-check ' + JS(a));
  }
  const ex = [
    { from: 0, to: 1, duration: 10, windows: [[0, 5]] },
    { from: 1, to: 3, duration: 5, windows: [[20, 30], [50, 60]] },
    { from: 0, to: 2, duration: 3, windows: [[10, 10]] },
    { from: 2, to: 3, duration: 30, windows: [[0, 100]] },
  ];
  const cases = [
    { name: 'example_from_prompt', args: [4, ex, 0, 3, 0], expect: 25 },
    { name: 'window_closed_take_other_route', args: [4, ex, 0, 3, 6], expect: 43 },
    { name: 'window_close_is_inclusive', args: [4, ex, 0, 3, 5], expect: 25 },
    { name: 'every_window_missed', args: [4, ex, 0, 3, 11], expect: -1 },
    { name: 'source_equals_target', args: [3, [], 2, 2, 77], expect: 77 },
    { name: 'unreachable_target', args: [3, [{ from: 0, to: 1, duration: 1, windows: [[0, 100]] }], 0, 2, 0], expect: -1 },
    { name: 'links_are_directed', args: [2, [{ from: 1, to: 0, duration: 1, windows: [[0, 100]] }], 0, 1, 0], expect: -1 },
    { name: 'empty_window_list_never_usable', args: [2, [{ from: 0, to: 1, duration: 1, windows: [] }], 0, 1, 0], expect: -1 },
    { name: 'wait_for_window_to_open', args: [2, [{ from: 0, to: 1, duration: 4, windows: [[100, 200]] }], 0, 1, 3], expect: 104 },
    { name: 'unsorted_overlapping_windows', args: [2, [{ from: 0, to: 1, duration: 1, windows: [[50, 60], [0, 5], [3, 20]] }], 0, 1, 18], expect: 19 },
    { name: 'between_windows_waits_for_next', args: [2, [{ from: 0, to: 1, duration: 1, windows: [[50, 60], [0, 5], [3, 20]] }], 0, 1, 21], expect: 51 },
    { name: 'parallel_links_pick_best', args: [2, [{ from: 0, to: 1, duration: 50, windows: [[0, 10]] }, { from: 0, to: 1, duration: 5, windows: [[30, 40]] }, { from: 0, to: 1, duration: 20, windows: [[0, 0]] }], 0, 1, 0], expect: 20 },
    { name: 'more_hops_can_be_faster', args: [4, [{ from: 0, to: 3, duration: 100, windows: [[0, 0]] }, { from: 0, to: 1, duration: 1, windows: [[0, 0]] }, { from: 1, to: 2, duration: 1, windows: [[1, 1]] }, { from: 2, to: 3, duration: 1, windows: [[2, 2]] }], 0, 3, 0], expect: 3 },
    { name: 'arriving_late_misses_connection', args: [4, [{ from: 0, to: 1, duration: 2, windows: [[0, 0]] }, { from: 1, to: 2, duration: 1, windows: [[1, 1]] }, { from: 2, to: 3, duration: 1, windows: [[2, 2]] }, { from: 1, to: 3, duration: 50, windows: [[0, 1000]] }], 0, 3, 0], expect: 52 },
    { name: 'self_loops_ignored', args: [2, [{ from: 0, to: 0, duration: 1, windows: [[0, 9]] }, { from: 0, to: 1, duration: 3, windows: [[5, 9]] }], 0, 1, 0], expect: 8 },
    { name: 'times_beyond_32_bits', args: [3, [{ from: 0, to: 1, duration: 1000000000, windows: [[999999999000, 1000000000000]] }, { from: 1, to: 2, duration: 999999999, windows: [[1000000000000, 1002000000000]] }], 0, 2, 5], expect: 1001999998999 },
    { name: 'single_point_window_exact_hit', args: [3, [{ from: 0, to: 1, duration: 7, windows: [[3, 3]] }, { from: 1, to: 2, duration: 1, windows: [[10, 10]] }], 0, 2, 3], expect: 11 },
  ];
  for (let k = 1; k <= 6; k++) {
    let a;
    do { a = randomNet(g.ri(4, 8), g.ri(8, 20)); } while (k <= 4 && transitBrute(...a) === -1);
    cases.push({ name: `random_small_${k}`, args: a, expect: transitBrute(...a) });
  }
  cases.push({ name: 'does_not_mutate_inputs', script: `const links = [{ from: 0, to: 1, duration: 2, windows: [[9, 12], [1, 3]] }]; const before = JSON.stringify(links); const t = earliestArrival(2, links, 0, 1, 4); return [t, JSON.stringify(links) === before];`, expect: [11, true] });
  const perfRandom = `${LCG(17)} const n = 100000, L = 200000; const links = []; for (let i = 0; i + 1 < n; i++) { const o = ri(0, 2000000); links.push({ from: i, to: i + 1, duration: ri(1, 100), windows: [[o, o + ri(0, 5000000)]] }); } while (links.length < L) { const w = []; const k = ri(1, 3); for (let j = 0; j < k; j++) { const o = ri(0, 5000000); w.push([o, o + ri(0, 400000)]); } links.push({ from: ri(0, n - 1), to: ri(0, n - 1), duration: ri(1, 200000), windows: w }); } return earliestArrival(n, links, 0, n - 1, 0);`;
  const perfChain = `const n = 100000; const links = []; for (let i = 0; i + 1 < n; i++) links.push({ from: i, to: i + 1, duration: 1, windows: [[i * 3, i * 3 + 1]] }); for (let i = 0; i + 2 < n; i += 2) links.push({ from: i + 2, to: i, duration: 1, windows: [[0, 1e12]] }); return earliestArrival(n, links, 0, n - 1, 0);`;
  const perfManyWindows = `${LCG(19)} const n = 50000; const links = []; for (let i = 0; i + 1 < n; i++) { const w = []; for (let j = 0; j < 4; j++) { const o = ri(0, 1000000000); w.push([o, o + ri(0, 1000)]); } links.push({ from: i, to: i + 1, duration: ri(1, 10), windows: w }); } for (let k = 0; k < 150000; k++) { const a = ri(0, n - 1); links.push({ from: a, to: Math.min(n - 1, a + ri(1, 50)), duration: ri(1, 5000000), windows: [[ri(0, 400000000), 1000000000]] }); } return earliestArrival(n, links, 0, n - 1, 0);`;
  cases.push({ name: 'perf_random_100k_nodes', script: perfRandom });
  cases.push({ name: 'perf_long_chain', script: perfChain });
  cases.push({ name: 'perf_many_windows_and_shortcuts', script: perfManyWindows });
  for (const [src, a, b] of [[perfRandom, 'n = 100000, L = 200000', 'n = 300, L = 900'], [perfManyWindows, 'n = 50000', 'n = 300'], [perfChain, 'n = 100000', 'n = 400']]) {
    let small = src.replace(a, b).replace('150000', '600');
    const run = (code) => { const c = vm.createContext({}); return JS(vm.runInContext(code + '\n;(function(){' + small + '})()', c)); };
    assert(run(readRef(slug)) === run(`function earliestArrival(...a) { return (${transitBrute.toString()})(...a); }`), 'transit perf cross-check');
  }
  return {
    slug, track: 'algo',
    title_en: 'Ferry Network with Departure Windows', title_ar: 'شبكة عبّارات بنوافذ مغادرة',
    summary_en: 'Earliest arrival in a directed network whose links can only be entered during given time windows — time-dependent shortest paths at 100 000 stations.',
    summary_ar: 'حساب أبكر وصول في شبكة موجّهة لا يمكن دخول روابطها إلا خلال نوافذ زمنية محددة — أقصر مسارات معتمدة على الزمن في مئة ألف محطة.',
    prompt: `# Ferry Network with Departure Windows

An archipelago runs ferries, cable cars and bridges that only operate during certain hours. A trip planner must answer: if I am at station \`source\` at time \`startTime\`, what is the earliest time I can be at station \`target\`?

Implement \`function earliestArrival(n, links, source, target, startTime)\`.

## Input
- \`n\` — number of stations, numbered \`0 … n−1\` (1 ≤ n ≤ 100 000).
- \`links\` — array of directed links \`{ from, to, duration, windows }\` (at most 200 000 links, at most 400 000 windows in total).
  - \`from\`, \`to\` are station numbers (a link may connect a station to itself).
  - \`duration\` is an integer, 1 ≤ duration ≤ 1 000 000 000.
  - \`windows\` is an array of \`[open, close]\` integer pairs (0 ≤ open ≤ close ≤ 10¹²), in no particular order and possibly overlapping. You may **depart** on the link at any time \`t\` with \`open ≤ t ≤ close\` for some window (both ends inclusive) and you arrive at \`to\` at \`t + duration\`. A link with no windows can never be used.
- \`source\`, \`target\` — station numbers; \`startTime\` — integer, 0 ≤ startTime ≤ 10¹².

You may wait at any station for as long as you like. Times can exceed 2³², so do not use 32-bit integer tricks on them.

## Return value
The earliest possible arrival time at \`target\` (an integer), \`startTime\` if \`source === target\`, or \`-1\` if \`target\` cannot be reached. Do not modify the inputs.

## Performance
Hidden tests use 100 000 stations and 200 000 links; the call must finish in well under a second in a browser. Expect something like O((n + L + W) · log) where W is the total number of windows.

## Example
\`\`\`js
const links = [
  { from: 0, to: 1, duration: 10, windows: [[0, 5]] },
  { from: 1, to: 3, duration: 5,  windows: [[20, 30], [50, 60]] },
  { from: 0, to: 2, duration: 3,  windows: [[10, 10]] },
  { from: 2, to: 3, duration: 30, windows: [[0, 100]] },
];
earliestArrival(4, links, 0, 3, 0);  // → 25  (leave at 0, reach 1 at 10, wait until 20, arrive at 25)
earliestArrival(4, links, 0, 3, 6);  // → 43  (0→1 has closed; wait for 0→2 at 10, arrive 13, then 2→3)
earliestArrival(4, links, 0, 3, 11); // → -1
\`\`\`

${jsContract('earliestArrival')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: [R.complexity(4), R.robustness(3), R.readability(2), R.idiomatic(1)],
    checks: { static: [STATIC_PARSES], tests: { entry: 'earliestArrival', timeout_ms: 4000, weight: 9, cases: await finalizeCases(slug, 'earliestArrival', cases) } },
  };
}

// ════════════════════════════════════════════ 4. line diff ═══════════════════════════════════════
const DIFF_CHECK = `const A = OLD === '' ? [] : OLD.split('\\n'); const B = NEW === '' ? [] : NEW.split('\\n'); const ops = lineDiff(OLD, NEW); if (!Array.isArray(ops)) return 'result is not an array'; const oa = [], ob = []; let edits = 0, phase = 0; for (const op of ops) { if (!Array.isArray(op) || op.length !== 2 || typeof op[1] !== 'string') return 'malformed op'; if (op[0] === '=') { oa.push(op[1]); ob.push(op[1]); phase = 0; } else if (op[0] === '-') { if (phase === 2) return 'addition before deletion inside a hunk'; oa.push(op[1]); edits++; phase = 1; } else if (op[0] === '+') { ob.push(op[1]); edits++; phase = 2; } else return 'unknown op ' + String(op[0]); } if (oa.length !== A.length || oa.some((l, i) => l !== A[i])) return 'old text not reproduced'; if (ob.length !== B.length || ob.some((l, i) => l !== B[i])) return 'new text not reproduced'; return edits;`;
const diffCase = (name, oldText, newText) => ({ name, script: `const OLD = ${JS(oldText)}; const NEW = ${JS(newText)}; ${DIFF_CHECK}`, expect: editDistanceDP(oldText, newText) });

async function diffTask() {
  const slug = 'algo-line-diff';
  const g = rng(404);
  const ctx = vm.createContext({});
  vm.runInContext(readRef(slug) + `\n;globalThis.__check = function (OLD, NEW) { ${DIFF_CHECK} };`, ctx);
  const randText = (n, alpha) => Array.from({ length: n }, () => g.pick(alpha)).join('\n');
  for (let k = 0; k < 4000; k++) {
    const al = g.pick([['a', 'b'], ['a', 'b', 'c'], ['x', 'y', 'z', 'w', ''], ['p', 'q', 'r', 's', 't', 'u']]);
    const a = g.rnd() < 0.1 ? '' : randText(g.ri(0, 40), al);
    const b = g.rnd() < 0.1 ? '' : randText(g.ri(0, 40), al);
    const got = ctx.__check(a, b);
    assert(got === editDistanceDP(a, b), `diff cross-check ${JS(a)} ${JS(b)} -> ${got}`);
  }
  const lines = (n, tag) => Array.from({ length: n }, (_, i) => `${tag} line ${i} — ${(i * 7919) % 104729}`).join('\n');
  const L_SRC = `const L = (n, tag) => Array.from({ length: n }, (_, i) => tag + ' line ' + i + ' — ' + ((i * 7919) % 104729)).join('\\n');`;
  const genDiffCase = (name, oldExpr, newExpr, expect) => ({ name, script: `${L_SRC} const OLD = ${oldExpr}; const NEW = ${newExpr}; ${DIFF_CHECK}`, expect });
  const cases = [
    diffCase('identical_texts', 'alpha\nbeta\ngamma', 'alpha\nbeta\ngamma'),
    diffCase('both_empty', '', ''),
    diffCase('old_empty', '', 'x\ny'),
    diffCase('new_empty', 'a\nb\nc', ''),
    diffCase('empty_vs_single_newline', '', '\n'),
    diffCase('trailing_newline_added', 'a\nb', 'a\nb\n'),
    diffCase('single_line_replaced', 'a\nb\nc', 'a\nX\nc'),
    diffCase('line_moved_to_end', 'a\nb\nc\nd', 'b\nc\nd\na'),
    diffCase('repeated_lines', 'a\na\na\nb', 'a\nb\na\na'),
    diffCase('myers_paper_example', 'A\nB\nC\nA\nB\nB\nA', 'C\nB\nA\nB\nA\nC'),
    diffCase('crlf_vs_lf_lines_differ', 'a\r\nb\r\n', 'a\nb\n'),
    diffCase('whitespace_is_significant', 'a \nb\n\tc', 'a\nb\nc'),
    diffCase('unicode_lines', 'مرحبا\n😀 emoji\nnaïve\n東京', 'مرحبا بكم\n😀 emoji\nnaïve\n東京\n🇸🇮'),
    diffCase('blank_lines_matter', 'a\n\n\nb', 'a\n\nb\n\n'),
    diffCase('swap_halves', lines(30, 'A') + '\n' + lines(30, 'B'), lines(30, 'B') + '\n' + lines(30, 'A')),
    genDiffCase('completely_different_2000', `L(2000, 'old')`, `L(2000, 'new')`, editDistanceDP(lines(2000, 'old'), lines(2000, 'new'))),
    genDiffCase('interleaved_insertions', `L(200, 'k')`, `L(200, 'k').split('\\n').flatMap((l, i) => (i % 3 === 0 ? [l, 'inserted ' + i] : [l])).join('\\n')`, editDistanceDP(lines(200, 'k'), lines(200, 'k').split('\n').flatMap((l, i) => (i % 3 === 0 ? [l, 'inserted ' + i] : [l])).join('\n'))),
  ];
  for (let k = 1; k <= 6; k++) cases.push(diffCase(`random_small_${k}`, randText(g.ri(20, 60), ['a', 'b', 'c', 'd']), randText(g.ri(20, 60), ['a', 'b', 'c', 'd'])));
  cases.push({ name: 'returns_fresh_ops_and_keeps_inputs', script: `const a = 'x\\ny', b = 'x\\nz'; const ops = lineDiff(a, b); return [a, b, ops.filter((o) => o[0] === '=').length];`, expect: ['x\ny', 'x\nz', 1] });
  // Large inputs: lines are unique, so the minimal edit count is known from the construction.
  const bigGen = (n, edits, seed, firstLast) => `${LCG(seed)} const n = ${n}; const base = []; for (let i = 0; i < n; i++) base.push('row ' + i + ' :: ' + ((i * 2654435761) >>> 0).toString(36)); const next = []; let want = 0; const touched = new Set(); for (let e = 0; e < ${edits}; e++) touched.add(ri(1, n - 2)); ${firstLast ? 'touched.add(0); touched.add(n - 1);' : ''} for (let i = 0; i < n; i++) { if (!touched.has(i)) { next.push(base[i]); continue; } const kind = i % 3; if (kind === 0) { next.push('changed ' + i); want += 2; } else if (kind === 1) { want += 1; } else { next.push(base[i]); next.push('inserted ' + i); want += 1; } } const OLD = base.join('\\n'), NEW = next.join('\\n'); const got = (function () { ${DIFF_CHECK} })(); return got === want ? 'minimal' : ['expected', want, 'got', got];`;
  cases.push({ name: 'perf_50000_lines_scattered_edits', script: bigGen(50000, 120, 23, false), expect: 'minimal' });
  cases.push({ name: 'perf_40000_lines_first_and_last_changed', script: bigGen(40000, 60, 29, true), expect: 'minimal' });
  cases.push({ name: 'perf_repetitive_lines', script: `${LCG(31)} const n = 30000; const base = []; for (let i = 0; i < n; i++) base.push(['{', '}', '  return x;'][ri(0, 2)]); const next = base.slice(); for (let e = 0; e < 40; e++) { const p = ri(0, next.length - 1); if (e % 2) next.splice(p, 1); else next.splice(p, 0, ['{', '}', '  return x;', 'new'][ri(0, 3)]); } const OLD = base.join('\\n'), NEW = next.join('\\n'); ${DIFF_CHECK}` });
  cases.push({ name: 'perf_large_insert_block', script: `const base = []; for (let i = 0; i < 30000; i++) base.push('L' + i); const next = base.slice(0, 15000).concat(Array.from({ length: 800 }, (_, i) => 'new block ' + i), base.slice(15000)); const OLD = base.join('\\n'), NEW = next.join('\\n'); ${DIFF_CHECK}`, expect: 800 });
  // cross-check the generated perf scripts at reduced size with the DP oracle
  for (const c of cases.filter((c) => c.name.startsWith('perf_repetitive'))) {
    const small = c.script.replace('const n = 30000', 'const n = 1500');
    const sctx = vm.createContext({});
    vm.runInContext(readRef(slug), sctx);
    const got = vm.runInContext('(function(){' + small.replace(DIFF_CHECK, 'return [OLD, NEW];') + '})()', sctx);
    assert(vm.runInContext('(function(){' + small + '})()', sctx) === editDistanceDP(got[0], got[1]), 'diff repetitive cross-check');
  }
  return {
    slug, track: 'algo',
    title_en: 'Minimal Line Diff', title_ar: 'أدنى فرق بين الأسطر',
    summary_en: 'Produce a shortest line-by-line edit script between two texts, fast enough for 50 000-line files with scattered changes.',
    summary_ar: 'إنتاج أقصر سلسلة تعديلات سطرًا بسطر بين نصّين، بسرعة تكفي لملفات من خمسين ألف سطر تتناثر فيها التغييرات.',
    prompt: `# Minimal Line Diff

A code-review tool needs a line diff that is both **minimal** and fast on large generated files.

Implement \`function lineDiff(oldText, newText)\`.

## Lines
Split each text into lines with \`text === "" ? [] : text.split("\\n")\`. Lines are compared exactly (case, whitespace and \`\\r\` are significant). Consequently a trailing \`"\\n"\` produces a final empty line, and \`"\\n"\` alone is two empty lines.

## Return value
An array of operations, each a two-element array \`[op, line]\`:
- \`["=", line]\` — the line is kept (present in both texts),
- \`["-", line]\` — the line is deleted from the old text,
- \`["+", line]\` — the line is added from the new text.

Requirements:
1. Reading the operations in order, the lines of the \`"="\` and \`"-"\` operations are exactly the old lines, and the lines of the \`"="\` and \`"+"\` operations are exactly the new lines.
2. The number of \`"-"\` plus \`"+"\` operations is the **minimum possible** (it equals old length + new length − 2 × the length of a longest common subsequence of lines).
3. Inside every maximal run of consecutive changes (between two \`"="\` operations or the ends of the list), all \`"-"\` operations come before all \`"+"\` operations.

When several minimal diffs exist, any of them is accepted.

## Performance
Hidden tests include files of 30 000–50 000 lines per side with up to ~250 changed lines scattered through the file (the first and last lines may differ too), and highly repetitive files (only a few distinct line values). Each call must finish in about a second in a browser, so an O(N·M) table is far too slow and too large; use an algorithm whose cost grows with the size of the difference (for example Myers' O((N+M)·D) algorithm). Pairs of completely different texts are at most 2 000 lines each.

## Example
\`\`\`js
lineDiff("a\\nb\\nc", "a\\nX\\nc\\nd");
// → [["=", "a"], ["-", "b"], ["+", "X"], ["=", "c"], ["+", "d"]]
\`\`\`

${jsContract('lineDiff')}`,
    output_type: 'js', difficulty: 4, visibility: 'public', auto_weight: null,
    rubric: [R.complexity(4), R.robustness(2), R.readability(3), R.idiomatic(1)],
    checks: { static: [STATIC_PARSES], tests: { entry: 'lineDiff', timeout_ms: 4000, weight: 9, cases: await finalizeCases(slug, 'lineDiff', cases) } },
  };
}

// ════════════════════════════════════════════ 5. rank agreement ══════════════════════════════════
async function rankTask() {
  const slug = 'algo-rank-agreement';
  const ref = load(slug, 'rankAgreement');
  const g = rng(505);
  const randVals = (n, k) => Array.from({ length: n }, () => g.ri(0, k) * (g.rnd() < 0.5 ? 1 : 0.5));
  for (let k = 0; k < 3000; k++) {
    const n = g.ri(0, 40);
    const x = randVals(n, g.ri(1, 6));
    const y = randVals(n, g.ri(1, 6));
    assert(compare(ref(x, y), rankBrute(x, y), 'approx', 1e-12), 'rank cross-check ' + JS([x, y]));
  }
  const cases = [
    { name: 'empty_lists', args: [[], []], expect: { concordant: 0, discordant: 0, tiesX: 0, tiesY: 0, tiesBoth: 0, tauB: null } },
    { name: 'single_model', args: [[3], [9]], expect: { concordant: 0, discordant: 0, tiesX: 0, tiesY: 0, tiesBoth: 0, tauB: null } },
    { name: 'perfect_agreement', args: [[1, 2, 3, 4], [10, 20, 30, 40]], expect: { concordant: 6, discordant: 0, tiesX: 0, tiesY: 0, tiesBoth: 0, tauB: 1 } },
    { name: 'perfect_reversal', args: [[1, 2, 3, 4], [4, 3, 2, 1]], expect: { concordant: 0, discordant: 6, tiesX: 0, tiesY: 0, tiesBoth: 0, tauB: -1 } },
    { name: 'mixed_ties', args: [[1, 2, 2, 3], [1, 3, 2, 2]], cmp: 'approx', tol: 1e-12, expect: { concordant: 3, discordant: 1, tiesX: 1, tiesY: 1, tiesBoth: 0, tauB: 0.4 } },
    { name: 'all_x_tied', args: [[5, 5, 5], [1, 2, 3]], expect: { concordant: 0, discordant: 0, tiesX: 3, tiesY: 0, tiesBoth: 0, tauB: null } },
    { name: 'everything_tied', args: [[7, 7, 7, 7], [2, 2, 2, 2]], expect: { concordant: 0, discordant: 0, tiesX: 0, tiesY: 0, tiesBoth: 6, tauB: null } },
    { name: 'negative_zero_equals_zero', args: [[0, -0, 1], [1, 2, 3]], cmp: 'approx', tol: 1e-12, expect: { concordant: 2, discordant: 0, tiesX: 1, tiesY: 0, tiesBoth: 0, tauB: 2 / Math.sqrt(6) } },
    { name: 'unsorted_input_order', args: [[3, 1, 2], [30, 10, 20]], expect: { concordant: 3, discordant: 0, tiesX: 0, tiesY: 0, tiesBoth: 0, tauB: 1 } },
    { name: 'fractional_scores', args: [[71.5, 71.25, 80, 71.5], [0.5, 0.75, 0.25, 0.5]], cmp: 'approx', tol: 1e-12, expect: rankBrute([71.5, 71.25, 80, 71.5], [0.5, 0.75, 0.25, 0.5]) },
    { name: 'extreme_magnitudes', args: [[1e308, -1e308, 0, 5e-324], [-1e308, 1e308, 0, 1]], cmp: 'approx', tol: 1e-12, expect: rankBrute([1e308, -1e308, 0, 5e-324], [-1e308, 1e308, 0, 1]) },
    { name: 'float_noise_is_not_a_tie', args: [[0.1 + 0.2, 0.3, 0.30000000000000004], [1, 2, 3]], cmp: 'approx', tol: 1e-12, expect: rankBrute([0.1 + 0.2, 0.3, 0.30000000000000004], [1, 2, 3]) },
    { name: 'length_mismatch_throws_range_error', script: `try { rankAgreement([1, 2], [1]); return 'no error'; } catch (e) { return e instanceof RangeError ? 'RangeError' : e.name; }`, expect: 'RangeError' },
    { name: 'does_not_mutate_inputs', script: `const x = [3, 1, 2, 2], y = [9, 8, 7, 7]; const before = JSON.stringify([x, y]); rankAgreement(x, y); return JSON.stringify([x, y]) === before;`, expect: true },
  ];
  for (let k = 1; k <= 6; k++) {
    const n = k < 4 ? 60 : 300;
    const x = randVals(n, k < 4 ? 8 : 40);
    const y = randVals(n, k < 4 ? 8 : 40);
    cases.push({ name: `random_${n}_${k}`, args: [x, y], cmp: 'approx', tol: 1e-12, expect: rankBrute(x, y) });
  }
  const perf = (name, seed, gen) => ({ name, script: `${LCG(seed)} const n = 200000; const x = new Array(n), y = new Array(n); for (let i = 0; i < n; i++) { ${gen} } return rankAgreement(x, y);`, cmp: 'approx', tol: 1e-9 });
  cases.push(perf('perf_200k_heavy_ties', 37, 'x[i] = ri(0, 999); y[i] = Math.floor(x[i] / 3) + ri(0, 400);'));
  cases.push(perf('perf_200k_distinct', 41, 'x[i] = rnd() * 1000; y[i] = x[i] * 0.7 + rnd() * 300;'));
  cases.push(perf('perf_200k_one_giant_tie', 43, 'x[i] = 1; y[i] = ri(0, 50);'));
  cases.push(perf('perf_200k_reversed', 47, 'x[i] = i; y[i] = -i * 2;'));
  for (const c of cases.filter((c) => c.name.startsWith('perf_'))) {
    const small = c.script.replace('const n = 200000', 'const n = 3000');
    const run = (code) => { const cx = vm.createContext({}); return vm.runInContext(code + '\n;(function(){' + small + '})()', cx); };
    assert(compare(run(readRef(slug)), run(`function rankAgreement(x, y) { return (${rankBrute.toString()})(x, y); }`), 'approx', 1e-9), 'rank perf cross-check ' + c.name);
  }
  return {
    slug, track: 'algo',
    title_en: 'Leaderboard Agreement (Kendall τ-b)', title_ar: 'مدى اتفاق لوحتَي ترتيب بمعامل كندال المصحَّح للتعادلات',
    summary_en: 'Count concordant, discordant and tied pairs between two score lists of 200 000 entries and compute Kendall’s tau-b exactly — O(n log n).',
    summary_ar: 'عدّ الأزواج المتوافقة والمتعاكسة والمتعادلة بين قائمتَي درجات من مئتي ألف عنصر، وحساب معامل كندال المصحَّح للتعادلات بدقة وبتعقيد O(n log n).',
    prompt: `# Leaderboard Agreement (Kendall τ-b)

Two benchmarks scored the same set of models. To quantify how much the two leaderboards agree we use Kendall's rank correlation with tie correction (tau-b).

Implement \`function rankAgreement(x, y)\`.

## Input
\`x\` and \`y\` are arrays of finite numbers of the same length \`n\` (0 ≤ n ≤ 200 000): \`x[i]\` and \`y[i]\` are the two scores of model \`i\`. Scores may repeat, may be negative or fractional; values are compared numerically (so \`-0\` equals \`0\`, while \`0.1 + 0.2\` and \`0.3\` are different).
If either argument is not an array or the lengths differ, throw a \`RangeError\`. Do not modify the inputs.

## Definitions
For every unordered pair of models \`i < j\` let \`dx = sign(x[i] − x[j])\` and \`dy = sign(y[i] − y[j])\`. The pair is
- **concordant** if \`dx · dy > 0\`, **discordant** if \`dx · dy < 0\`,
- **tiesX** if \`dx = 0\` and \`dy ≠ 0\`, **tiesY** if \`dy = 0\` and \`dx ≠ 0\`, **tiesBoth** if \`dx = dy = 0\`.

\`tauB = (concordant − discordant) / sqrt((concordant + discordant + tiesY) · (concordant + discordant + tiesX))\`, or \`null\` when that denominator is 0.

## Return value
\`{ concordant, discordant, tiesX, tiesY, tiesBoth, tauB }\` — the five counts are exact integers (they can exceed 2³¹ for large n, but stay below 2⁵³) and \`tauB\` is a number or \`null\`.

## Performance
Hidden tests use n = 200 000 (about 2·10¹⁰ pairs), including inputs where almost everything is tied. Each call must finish in about a second in a browser: an O(n log n) algorithm is required.

## Example
\`\`\`js
rankAgreement([1, 2, 2, 3], [1, 3, 2, 2]);
// → { concordant: 3, discordant: 1, tiesX: 1, tiesY: 1, tiesBoth: 0, tauB: 0.4 }
\`\`\`

${jsContract('rankAgreement')}`,
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: [R.complexity(4), R.robustness(3), R.readability(2), R.idiomatic(1)],
    checks: { static: [STATIC_PARSES], tests: { entry: 'rankAgreement', timeout_ms: 4000, weight: 9, cases: await finalizeCases(slug, 'rankAgreement', cases) } },
  };
}

const tasks = [];
for (const make of [studioTask, sheetTask, transitTask, diffTask, rankTask]) tasks.push(await make());
writeTrack('algo', tasks);
