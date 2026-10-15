// DEV ONLY — builds sql/seed/tasks/debug.json and writes each task's original buggy code to
// tools/dev/references/mutants/<slug>.original.js (it must fail the tests).
//   node tools/dev/references/build/debug.mjs
import { writeFileSync } from 'node:fs';
import { REF, finalizeCases, jsContract, STATIC_PARSES, R, writeTrack, LCG } from './common.mjs';

const JS = (v) => JSON.stringify(v);
const writeOriginal = (slug, code) => writeFileSync(REF(`mutants/${slug}.original.js`), `// MUTANT: the original buggy code shown in the prompt.\n${code}\n`);
const debugPrompt = ({ title, context, code, reports, extra = '' }, entry) => `# ${title}

${context}

The doc comment describes the intended behaviour and is correct; the implementation is not. Users filed the bug reports below. Find the root causes, fix **all** defects (including ones the reports only hint at) and keep the same function name and signature.

## Current code
\`\`\`js
${code}
\`\`\`

## Bug reports
${reports.map((r, i) => `${i + 1}. ${r}`).join('\n')}
${extra}
${jsContract(entry)}
Return the complete fixed code (the documented function plus any helpers), not a diff.`;

const RUBRIC = [R.diagnosis(3), R.robustness(3), R.minimal(2), R.readability(2)];

// ════════════════════════════════════════════ 1. invoice totals ══════════════════════════════════
const INVOICE_BUGGY = `/**
 * Builds the totals block of an invoice.
 *
 * lines: [{ sku: string, unitPrice: number, qty: number, taxRate: number }]
 *   unitPrice ≥ 0 with at most 4 decimals; qty an integer (negative for returned items);
 *   taxRate between 0 and 1 with at most 4 decimals (0.2 = 20 %).
 * opts.discountPercent: 0–100 with at most 2 decimals, applied to every line before tax (default 0).
 *
 * Returns a new object { lines: [{ sku, net, tax, gross }], net, tax, gross } where
 *   line net   = unitPrice × qty × (1 − discountPercent / 100), rounded to cents
 *   line tax   = line net × taxRate, rounded to cents
 *   line gross = line net + line tax
 *   net / tax / gross = sums of the line values.
 * All arithmetic is exact decimal arithmetic on the values as written (like the accounting system),
 * rounding is half away from zero (2.345 → 2.35, −2.345 → −2.35), and every amount is returned as
 * a Number equal to (an integer number of cents) / 100.
 * Lines are listed sorted by sku in plain UTF-16 code-unit order (the accounting export's order);
 * lines with equal skus keep their input order. The caller's array and objects are not modified.
 */
function invoiceTotals(lines, opts = {}) {
  const pct = opts.discountPercent || 0;
  const sorted = lines.sort((a, b) => a.sku.localeCompare(b.sku));
  const out = [];
  let net = 0;
  let tax = 0;
  for (const line of sorted) {
    const lineNet = round2(line.unitPrice * line.qty * (1 - pct / 100));
    const lineTax = round2(lineNet * line.taxRate);
    out.push({ sku: line.sku, net: lineNet, tax: lineTax, gross: lineNet + lineTax });
    net += lineNet;
    tax += lineTax;
  }
  return { lines: out, net: round2(net), tax: round2(tax), gross: round2(net + tax) };
}

function round2(x) {
  return Math.round(x * 100) / 100;
}`;

const L = (sku, unitPrice, qty, taxRate) => ({ sku, unitPrice, qty, taxRate });
async function invoiceTask() {
  const slug = 'debug-invoice-totals';
  writeOriginal(slug, INVOICE_BUGGY);
  const one = (name, line, opts, expectLine) => ({ name, args: opts === undefined ? [[line]] : [[line], opts], expect: { lines: [{ sku: line.sku, ...expectLine }], net: expectLine.net, tax: expectLine.tax, gross: expectLine.gross } });
  const cases = [
    one('simple_line', L('A', 10, 2, 0.2), undefined, { net: 20, tax: 4, gross: 24 }),
    { name: 'empty_invoice', args: [[]], expect: { lines: [], net: 0, tax: 0, gross: 0 } },
    one('half_cent_1_005', L('A', 1.005, 1, 0), {}, { net: 1.01, tax: 0, gross: 1.01 }),
    one('half_cent_product_0_075_x3', L('A', 0.075, 3, 0), {}, { net: 0.23, tax: 0, gross: 0.23 }),
    one('half_cent_product_0_345_x9', L('A', 0.345, 9, 0), {}, { net: 3.11, tax: 0, gross: 3.11 }),
    one('half_cent_product_0_565_x13', L('A', 0.565, 13, 0), {}, { net: 7.35, tax: 0, gross: 7.35 }),
    one('half_cent_product_0_015_x7', L('A', 0.015, 7, 0), {}, { net: 0.11, tax: 0, gross: 0.11 }),
    one('half_cent_product_2_675', L('A', 0.535, 5, 0), {}, { net: 2.68, tax: 0, gross: 2.68 }),
    one('refund_rounds_away_from_zero', L('R', 0.125, -1, 0), {}, { net: -0.13, tax: 0, gross: -0.13 }),
    one('refund_with_tax', L('R', 1.005, -3, 0.2), {}, { net: -3.02, tax: -0.6, gross: -3.62 }),
    one('tax_half_cent', L('T', 0.05, 1, 0.5), {}, { net: 0.05, tax: 0.03, gross: 0.08 }),
    one('tax_on_rounded_net', L('T', 0.333, 3, 0.15), {}, { net: 1, tax: 0.15, gross: 1.15 }),
    one('gross_exact_cents', L('G', 0.1, 1, 0.2), {}, { net: 0.1, tax: 0.02, gross: 0.12 }),
    one('gross_exact_cents_2', L('G', 1.1, 3, 0.07), {}, { net: 3.3, tax: 0.23, gross: 3.53 }),
    one('discount_12_5', L('D', 19.99, 3, 0.2), { discountPercent: 12.5 }, { net: 52.47, tax: 10.49, gross: 62.96 }),
    one('discount_33_33', L('D', 0.3, 1, 0), { discountPercent: 33.33 }, { net: 0.2, tax: 0, gross: 0.2 }),
    one('discount_exact_half_cent', L('D', 0.45, 1, 0), { discountPercent: 50 }, { net: 0.23, tax: 0, gross: 0.23 }),
    one('discount_100_percent', L('F', 99.99, 7, 0.2), { discountPercent: 100 }, { net: 0, tax: 0, gross: 0 }),
    one('large_quantity_exact', L('B', 9999.9999, 1000000, 0.0001), {}, { net: 9999999900, tax: 999999.99, gross: 10000999899.99 }),
    one('four_decimal_price_tiny_tax', L('P', 0.0001, 49, 0.0725), {}, { net: 0, tax: 0, gross: 0 }),
    { name: 'totals_are_sums_of_rounded_lines', args: [[L('a', 0.005, 1, 0), L('b', 0.005, 1, 0), L('c', 0.005, 1, 0)]], expect: { lines: [{ sku: 'a', net: 0.01, tax: 0, gross: 0.01 }, { sku: 'b', net: 0.01, tax: 0, gross: 0.01 }, { sku: 'c', net: 0.01, tax: 0, gross: 0.01 }], net: 0.03, tax: 0, gross: 0.03 } },
    { name: 'many_dimes_total', args: [Array.from({ length: 10 }, (_, i) => L('x' + i, 0.1, 1, 0.1))] },
    { name: 'sku_code_unit_order', script: `const r = invoiceTotals([['b-200', 1], ['B-100', 2], ['a-1', 3], ['A-2', 4], ['ä-3', 5], ['10', 6], ['9', 7]].map(([sku, p]) => ({ sku, unitPrice: p, qty: 1, taxRate: 0 }))); return r.lines.map((l) => l.sku);`, expect: ['10', '9', 'A-2', 'B-100', 'a-1', 'b-200', 'ä-3'] },
    { name: 'equal_skus_keep_input_order', script: `const r = invoiceTotals([{ sku: 'X', unitPrice: 3, qty: 1, taxRate: 0 }, { sku: 'A', unitPrice: 9, qty: 1, taxRate: 0 }, { sku: 'X', unitPrice: 1, qty: 1, taxRate: 0 }, { sku: 'X', unitPrice: 2, qty: 1, taxRate: 0 }]); return r.lines.map((l) => l.sku + l.net);`, expect: ['A9', 'X3', 'X1', 'X2'] },
    { name: 'does_not_mutate_caller_array', script: `const lines = [{ sku: 'z', unitPrice: 1, qty: 1, taxRate: 0 }, { sku: 'a', unitPrice: 2, qty: 1, taxRate: 0 }]; const before = JSON.stringify(lines); const r = invoiceTotals(lines, { discountPercent: 10 }); return [JSON.stringify(lines) === before, r.lines[0] !== lines[1], r.net];`, expect: [true, true, 2.7] },
    { name: 'mixed_invoice_realistic', args: [[L('SRV-02', 149.95, 2, 0.19), L('HW-11', 12.345, 4, 0.19), L('RET-7', 12.345, -1, 0.19), L('BOOK', 23.4, 1, 0.07), L('FEE', 0.995, 3, 0)], { discountPercent: 7.5 }] },
    { name: 'random_lines_exact', script: `${LCG(61)} const lines = []; for (let i = 0; i < 400; i++) lines.push({ sku: 'S' + ri(0, 50), unitPrice: ri(0, 999999) / 10000, qty: ri(-5, 40), taxRate: ri(0, 2500) / 10000 }); const r = invoiceTotals(lines, { discountPercent: ri(0, 9999) / 100 }); let h = 0; for (const l of r.lines) h = (h * 31 + Math.round(l.net * 100) * 7 + Math.round(l.tax * 100) * 3 + Math.round(l.gross * 100)) % 1000000007; const exactCents = r.lines.every((l) => [l.net, l.tax, l.gross].every((v) => Math.round(v * 100) / 100 === v)) && l2(r); function l2(x) { return [x.net, x.tax, x.gross].every((v) => Math.round(v * 100) / 100 === v); } return [h, r.net, r.tax, r.gross, exactCents];` },
    { name: 'random_half_cent_lines', script: `${LCG(67)} const lines = []; for (let i = 0; i < 300; i++) { const q = 2 * ri(0, 20) + 1; lines.push({ sku: 'H' + i, unitPrice: (ri(0, 9999) * 10 + 5) / 1000, qty: i % 4 === 0 ? -q : q, taxRate: ri(0, 2000) / 10000 }); } const r = invoiceTotals(lines); return [r.lines.slice(0, 5), r.net, r.tax, r.gross];` },
  ];
  return {
    slug, track: 'debug',
    title_en: 'Fix the Invoice Totals', title_ar: 'إصلاح مجاميع الفاتورة',
    summary_en: 'Repair an invoice calculator: binary floating-point rounding, half-cent refunds, locale-dependent sorting and a caller-visible mutation.',
    summary_ar: 'إصلاح حاسبة فواتير تعاني من أخطاء التقريب في الفاصلة العائمة، وتقريب أنصاف السنتات في المرتجعات، وفرز يعتمد على اللغة المحلية، وتعديل مصفوفة المستدعي.',
    prompt: debugPrompt({
      title: 'Fix the Invoice Totals',
      context: 'This function builds the totals block of invoices for a small online shop. Finance compares every invoice with the accounting system, which works with exact decimal numbers.',
      code: INVOICE_BUGGY,
      reports: [
        'After the invoice preview is rendered, the shopping cart shows its items in a different order than the customer added them.',
        'Finance reports one-cent differences: a line of 1 × 1.005 is billed as 1.00 instead of 1.01, and 3 × 0.075 as 0.22 instead of 0.23.',
        'Refunds are off by a cent in the customer’s favour: a returned item at 0.125 shows −0.12 instead of −0.13.',
        'The JSON export contains amounts such as `"gross": 0.12000000000000001`.',
        'Invoices with SKUs `b-200` and `B-100` list the lines in a different order than the accounting export, and the order differs between our Node server and some browsers.',
      ],
    }, 'invoiceTotals'),
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [STATIC_PARSES], tests: { entry: 'invoiceTotals', timeout_ms: 2000, weight: 9, cases: await finalizeCases(slug, 'invoiceTotals', cases) } },
  };
}

// ════════════════════════════════════════════ 2. preview truncate ═════════════════════════════════
const PREVIEW_BUGGY = `/**
 * Shortens a message for a notification preview.
 *
 * Rules (U = the text as an array of Unicode code points; "space" means U+0020):
 *  1. If U has at most maxLen code points, return the text unchanged.
 *  2. Otherwise keep the first maxLen − 1 code points (one position is reserved for "…").
 *  3. If the first dropped code point is not a space, and the kept part contains a space at a
 *     code-point index ≥ floor((maxLen − 1) / 2), cut the kept part at its LAST space
 *     (that space and everything after it are dropped).
 *  4. Remove trailing whitespace (as matched by /\\s/) from the kept part and append "…" (U+2026).
 * maxLen is an integer ≥ 1. Leading whitespace is part of the message and must be preserved.
 */
function previewText(text, maxLen) {
  if (text.length <= maxLen) return text;
  let kept = text.slice(0, maxLen);
  const lastSpace = kept.lastIndexOf(' ');
  if (lastSpace > maxLen / 2) kept = kept.slice(0, lastSpace);
  return kept.trim() + '…';
}`;

async function previewTask() {
  const slug = 'debug-preview-truncate';
  writeOriginal(slug, PREVIEW_BUGGY);
  const c = (name, text, maxLen, expect) => ({ name, args: [text, maxLen], expect });
  const cases = [
    c('fits_unchanged', 'Short note', 20, 'Short note'),
    c('exact_length_unchanged', 'abcdefghij', 10, 'abcdefghij'),
    c('emoji_counts_as_one_code_point', 'Party 🎉🎉🎉', 9, 'Party 🎉🎉🎉'),
    c('one_too_long_hard_cut', 'abcdefghijk', 10, 'abcdefghi…'),
    c('result_never_exceeds_limit', 'The quick brown fox jumps over the lazy dog', 20, 'The quick brown fox…'),
    c('cut_at_last_space', 'Meeting moved to Thursday afternoon', 22, 'Meeting moved to…'),
    c('first_dropped_is_space_keeps_word', 'Hello wonderful world', 16, 'Hello wonderful…'),
    c('first_dropped_is_space_keeps_word_2', 'one two three four', 14, 'one two three…'),
    c('space_exactly_at_threshold', 'aaaaa bbbbbbbbbbbb', 11, 'aaaaa…'),
    c('space_just_below_threshold', 'aaaa bbbbbbbbbbbbb', 11, 'aaaa bbbbb…'),
    c('threshold_with_odd_limit', 'abc defghijklmnop', 8, 'abc…'),
    c('no_spaces_long_word', 'Supercalifragilisticexpialidocious', 12, 'Supercalifr…'),
    c('emoji_not_split', 'Go🚀🚀🚀🚀🚀🚀', 5, 'Go🚀🚀…'),
    c('emoji_only', '😀😃😄😁😆😅', 4, '😀😃😄…'),
    c('leading_whitespace_preserved', '    indented code sample here', 14, '    indented…'),
    c('trailing_whitespace_trimmed_before_ellipsis', 'Alpha\t\t\tBravo', 8, 'Alpha…'),
    c('newline_whitespace_trimmed', 'Line one\n\nLine two', 11, 'Line one…'),
    c('max_len_one', 'Hello', 1, '…'),
    c('max_len_two', 'Hello', 2, 'H…'),
    c('only_spaces', '        ', 3, '…'),
    c('arabic_text', 'مرحبًا بكم في منصة المقارنة الجديدة', 20, 'مرحبًا بكم في منصة…'),
    c('combining_marks_are_code_points', 'Café au lait été', 9, 'Café au…'),
    c('zwj_sequence_counts_each_code_point', 'Team👩\u200D💻👩\u200D💻 rocks', 7, 'Team👩\u200D…'),
    { name: 'lone_surrogate_input_is_one_code_point', script: "const r = previewText('ab\\uD83Dcdefgh', 5); return Array.from(r).map((ch) => ch.codePointAt(0).toString(16));", expect: ['61', '62', 'd83d', '63', '2026'] },
    c('cjk_text', '東京都の天気予報は晴れです', 8, '東京都の天気予…'),
    { name: 'never_produces_lone_surrogates', script: `const out = []; const text = 'Results 🥇🥈🥉 for 🇸🇮 and 🇦🇪 teams 🎯🎯🎯'; for (let n = 1; n <= 40; n++) { const r = previewText(text, n); if (/[\\uD800-\\uDBFF](?![\\uDC00-\\uDFFF])|(^|[^\\uD800-\\uDBFF])[\\uDC00-\\uDFFF]/.test(r)) out.push(n); if (Array.from(r).length > n) out.push('long' + n); } return out;`, expect: [] },
    { name: 'long_text_performance', script: `const words = []; for (let i = 0; i < 200000; i++) words.push('w' + (i % 97)); const t = words.join(' '); const r = previewText(t, 50000); return [Array.from(r).length <= 50000, r.endsWith('…'), !r.endsWith(' …'), r.startsWith('w0 w1 w2')];`, expect: [true, true, true, true] },
  ];
  return {
    slug, track: 'debug',
    title_en: 'Fix the Notification Preview', title_ar: 'إصلاح معاينة الإشعارات',
    summary_en: 'Repair a text-truncation helper that splits emoji, overshoots its limit by one, cuts words on the wrong boundary and eats leading indentation.',
    summary_ar: 'إصلاح دالة اقتطاع نصوص تقسم الرموز التعبيرية وتتجاوز الحد بحرف واحد وتقطع الكلمات عند الموضع الخطأ وتحذف المسافات البادئة.',
    prompt: debugPrompt({
      title: 'Fix the Notification Preview',
      context: 'A messaging app shortens long messages for push-notification previews with the helper below. The rules in its doc comment come from the design team and are authoritative.',
      code: PREVIEW_BUGGY,
      reports: [
        'Push notifications sometimes end with a broken “�” character instead of an emoji, and the iOS payload validator rejects some of them as invalid UTF-16.',
        'Previews can be one character longer than the configured limit (limit 10 → 11 characters).',
        'Messages that fit exactly (for example ones containing emoji) are still shortened.',
        'Code snippets lose their indentation in previews: “    indented code” becomes “indented…”.',
        'Product noticed that the word-boundary rule does not behave as documented for some lengths (a space exactly at the threshold, and cuts that fall right before a space). Please make it follow the doc comment exactly.',
      ],
    }, 'previewText'),
    output_type: 'js', difficulty: 2, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [STATIC_PARSES], tests: { entry: 'previewText', timeout_ms: 2000, weight: 9, cases: await finalizeCases(slug, 'previewText', cases) } },
  };
}

// ════════════════════════════════════════════ 3. renewal dates ═══════════════════════════════════
const RENEWAL_BUGGY = `/**
 * Upcoming renewal dates of a subscription.
 *
 * startDate  "YYYY-MM-DD" — the day the subscription started (a calendar date, year ≥ 1900).
 * months     renewal period in months, an integer from 1 to 1200.
 * afterDate  "YYYY-MM-DD" — only renewals strictly after this date are returned (year ≥ 1900).
 * count      how many dates to return, an integer from 0 to 1000.
 *
 * Renewal k (k = 1, 2, 3, …) falls k × months months after startDate, on the same day of the
 * month as startDate; if the target month is shorter, it falls on that month's last day
 * (start 2024-01-31, monthly → 2024-02-29, 2024-03-31, 2024-04-30, …).
 * Returns the first \`count\` renewals after afterDate as "YYYY-MM-DD" strings, in increasing order.
 * The result must not depend on the time zone of the machine running the code.
 * Throws a RangeError for a malformed date string (anything but exactly YYYY-MM-DD), an
 * impossible date (2026-02-30, month 13, day 00), a year before 1900, or months/count outside
 * their ranges (including non-integers).
 */
function nextRenewals(startDate, months, afterDate, count) {
  const [y, m, d] = startDate.split('-').map(Number);
  const after = new Date(afterDate);
  const result = [];
  let current = new Date(Date.UTC(y, m, d));
  while (result.length < count) {
    current.setUTCMonth(current.getUTCMonth() + months);
    if (current > after) result.push(current.toISOString().slice(0, 10));
  }
  return result;
}`;

async function renewalTask() {
  const slug = 'debug-renewal-dates';
  writeOriginal(slug, RENEWAL_BUGGY);
  const c = (name, args, expect) => ({ name, args, expect });
  const errs = (calls) => `const calls = ${calls}; return calls.map((args) => { try { nextRenewals(...args); return 'ok'; } catch (e) { return e instanceof RangeError ? 'RangeError' : (e && e.name) || 'error'; } });`;
  const cases = [
    c('monthly_mid_month', ['2026-01-15', 1, '2026-01-15', 3], ['2026-02-15', '2026-03-15', '2026-04-15']),
    c('first_renewal_is_one_period_later', ['2026-05-02', 1, '2000-01-01', 1], ['2026-06-02']),
    c('month_end_anchor_leap_year', ['2024-01-31', 1, '2024-01-01', 6], ['2024-02-29', '2024-03-31', '2024-04-30', '2024-05-31', '2024-06-30', '2024-07-31']),
    c('month_end_anchor_common_year', ['2025-01-31', 1, '2025-01-31', 3], ['2025-02-28', '2025-03-31', '2025-04-30']),
    c('day_30_through_february', ['2025-11-30', 1, '2025-12-01', 4], ['2025-12-30', '2026-01-30', '2026-02-28', '2026-03-30']),
    c('yearly_from_leap_day', ['2024-02-29', 12, '2024-02-29', 5], ['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29', '2029-02-28']),
    c('century_not_leap', ['2096-02-29', 48, '2096-03-01', 2], ['2100-02-28', '2104-02-29']),
    c('four_hundred_rule_leap', ['1996-02-29', 48, '1996-02-29', 2], ['2000-02-29', '2004-02-29']),
    c('quarterly_from_aug_31', ['2025-08-31', 3, '2025-08-31', 4], ['2025-11-30', '2026-02-28', '2026-05-31', '2026-08-31']),
    c('december_rollover', ['2026-11-30', 1, '2026-11-30', 3], ['2026-12-30', '2027-01-30', '2027-02-28']),
    c('after_date_equal_to_renewal_excluded', ['2026-01-10', 2, '2026-05-10', 2], ['2026-07-10', '2026-09-10']),
    c('after_date_far_in_future', ['2000-01-31', 1, '2030-02-27', 2], ['2030-02-28', '2030-03-31']),
    c('after_date_before_start', ['2026-03-31', 6, '1999-12-31', 3], ['2026-09-30', '2027-03-31', '2027-09-30']),
    c('count_zero', ['2026-01-01', 1, '2026-01-01', 0], []),
    c('century_period', ['1950-06-15', 1200, '1950-06-15', 1], ['2050-06-15']),
    c('odd_period_17_months', ['2023-03-31', 17, '2023-01-01', 3], ['2024-08-31', '2026-01-31', '2027-06-30']),
    c('many_periods_later', ['1900-01-31', 1, '9990-01-01', 3], ['9990-01-31', '9990-02-28', '9990-03-31']),
    { name: 'thousand_monthly_renewals', script: `const r = nextRenewals('1901-03-31', 1, '1901-03-31', 1000); return [r.length, r[0], r[10], r[999], r.every((d, i) => i === 0 || d > r[i - 1])];`, expect: [1000, '1901-04-30', '1902-02-28', '1984-07-31', true] },
    { name: 'impossible_dates_rejected', script: errs(`[['2026-02-30', 1, '2026-01-01', 1], ['2026-13-01', 1, '2026-01-01', 1], ['2026-00-10', 1, '2026-01-01', 1], ['2026-04-31', 1, '2026-01-01', 1], ['2025-02-29', 12, '2026-01-01', 1], ['2026-01-01', 1, '2026-06-31', 1], ['1899-12-31', 1, '2026-01-01', 1], ['2024-02-29', 1, '2026-01-01', 1]]`), expect: ['RangeError', 'RangeError', 'RangeError', 'RangeError', 'RangeError', 'RangeError', 'RangeError', 'ok'] },
    { name: 'malformed_dates_rejected', script: errs(`[['2026-1-05', 1, '2026-01-01', 1], ['26-01-05', 1, '2026-01-01', 1], ['2026-01-05T00:00:00Z', 1, '2026-01-01', 1], ['', 1, '2026-01-01', 1], ['2026/01/05', 1, '2026-01-01', 1], ['2026-01-05', 1, '2026-01-01 ', 1], [20260105, 1, '2026-01-01', 1], ['2026-01-05', 1, null, 1]]`), expect: Array(8).fill('RangeError') },
    { name: 'zero_months_rejected_not_hanging', script: errs(`[['2026-01-05', 0, '2026-01-01', 1]]`), expect: ['RangeError'] },
    { name: 'bad_months_rejected', script: errs(`[['2026-01-05', -1, '2026-01-01', 1], ['2026-01-05', 1.5, '2026-01-01', 1], ['2026-01-05', 1201, '2026-01-01', 1], ['2026-01-05', '3', '2026-01-01', 1], ['2026-01-05', NaN, '2026-01-01', 1], ['2026-01-05', 1200, '2026-01-01', 1]]`), expect: ['RangeError', 'RangeError', 'RangeError', 'RangeError', 'RangeError', 'ok'] },
    { name: 'bad_count_rejected', script: errs(`[['2026-01-05', 1, '2026-01-01', -1], ['2026-01-05', 1, '2026-01-01', 2.5], ['2026-01-05', 1, '2026-01-01', 1001], ['2026-01-05', 1, '2026-01-01', 1000]]`), expect: ['RangeError', 'RangeError', 'RangeError', 'ok'] },
    c('after_date_inside_clamped_month', ['2024-01-31', 1, '2024-02-28', 2], ['2024-02-29', '2024-03-31']),
  ];
  return {
    slug, track: 'debug',
    title_en: 'Fix the Subscription Renewal Dates', title_ar: 'إصلاح مواعيد تجديد الاشتراكات',
    summary_en: 'Repair a billing-date helper with a 0-based month slip, month-end overflow, day drift after short months and an input that hangs the server.',
    summary_ar: 'إصلاح دالة مواعيد الفوترة: انزياح الشهر بسبب الترقيم من الصفر، وتجاوز نهاية الشهر، وانجراف اليوم بعد الأشهر القصيرة، ومدخلات تُعلّق الخادم.',
    prompt: debugPrompt({
      title: 'Fix the Subscription Renewal Dates',
      context: 'A SaaS billing service uses this helper to show customers their next renewal dates and to schedule charges.',
      code: RENEWAL_BUGGY,
      reports: [
        'A customer who subscribed on 2026-01-15 (monthly) sees 2026-03-15 as the first renewal; it should be 2026-02-15.',
        'Customers who subscribed on the 31st get renewal dates on the 2nd or 3rd of a month (for example 2026-03-03 for a subscription started on 2025-12-31) where we expect the last day of the shorter month.',
        'Once a renewal date has shifted like that, every later renewal stays on the new day (…-03, …-03, …) instead of returning to the original day of the month whenever the month is long enough.',
        'A request with `months: 0` (a bad config value) kept a worker at 100 % CPU until it was killed; requests with dates such as `2026-02-30` produce nonsense instead of an error.',
      ],
    }, 'nextRenewals'),
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [STATIC_PARSES], tests: { entry: 'nextRenewals', timeout_ms: 2000, weight: 9, cases: await finalizeCases(slug, 'nextRenewals', cases) } },
  };
}

// ════════════════════════════════════════════ 4. async pool ══════════════════════════════════════
const POOL_BUGGY = `/**
 * Runs asynchronous jobs with a concurrency limit.
 *
 * jobs  — array of functions (up to 50 000); each returns a promise or a plain value, and may throw.
 * limit — maximum number of jobs running at the same time; must be a positive integer.
 *
 * Jobs are started in array order, and a new job starts as soon as a running one finishes.
 * Resolves to the array of results in the same order as \`jobs\` ([] for no jobs).
 * If a job throws or its promise rejects, the returned promise rejects with that same error and
 * no further jobs are started (jobs already running are left alone; their outcomes are ignored).
 * A job that throws synchronously fails at that moment, before any later job is started.
 * If \`limit\` is not a positive integer, the returned promise rejects with a RangeError.
 */
async function runPool(jobs, limit) {
  const results = [];
  let next = 0;
  let active = 0;
  return new Promise((resolve, reject) => {
    const launch = () => {
      while (active <= limit && next < jobs.length) {
        const job = jobs[next++];
        active++;
        job().then((value) => {
          results.push(value);
          active--;
          if (results.length === jobs.length) resolve(results);
          else launch();
        }, reject);
      }
    };
    launch();
  });
}`;

const POOL_KIT = `const tick = () => new Promise((r) => setTimeout(r, 0)); const ctl = { started: [], running: 0, max: 0, d: [] }; const mk = (n) => Array.from({ length: n }, (_, i) => () => { ctl.started.push(i); ctl.running++; ctl.max = Math.max(ctl.max, ctl.running); return new Promise((res, rej) => { const d = { done: false, ok: (v) => { if (d.done) return; d.done = true; ctl.running--; res(v); }, fail: (e) => { if (d.done) return; d.done = true; ctl.running--; rej(e); } }; ctl.d[i] = d; }); }); const finishAll = async () => { for (let k = 0; k < 12; k++) { await tick(); ctl.d.forEach((d, i) => d && d.ok('r' + i)); } }; const settle = (p) => p.then((v) => ({ ok: v }), (e) => ({ err: e && e.message !== undefined ? e.message : String(e), type: e && e.name })); const within = (p, ms) => Promise.race([p, new Promise((r) => setTimeout(() => r('still pending'), ms))]);`;
const asyncCase = (name, body, expect) => ({ name, script: `${POOL_KIT} return (async () => { ${body} })();`, expect });

async function poolTask() {
  const slug = 'debug-async-pool';
  writeOriginal(slug, POOL_BUGGY);
  const cases = [
    asyncCase('limit_is_respected', `const P = settle(runPool(mk(6), 2)); await tick(); const s1 = ctl.started.length; ctl.d[1].ok('b'); await tick(); const s2 = ctl.started.length; await finishAll(); const r = await within(P, 300); return { s1, s2, max: ctl.max, r };`,
      { s1: 2, s2: 3, max: 2, r: { ok: ['r0', 'b', 'r2', 'r3', 'r4', 'r5'] } }),
    asyncCase('results_in_input_order', `const P = settle(runPool(mk(4), 4)); await tick(); for (const i of [3, 1, 2, 0]) { ctl.d[i].ok('v' + i); await tick(); } return await P;`, { ok: ['v0', 'v1', 'v2', 'v3'] }),
    asyncCase('empty_job_list_resolves', `return await within(settle(runPool([], 3)), 200);`, { ok: [] }),
    asyncCase('plain_values_and_async_functions', `return await within(settle(runPool([() => 1, () => Promise.resolve(2), async () => 3, () => 'four', () => null], 2)), 500);`, { ok: [1, 2, 3, 'four', null] }),
    asyncCase('undefined_results_kept_in_place', `const r = await within(settle(runPool([() => undefined, async () => 7, () => undefined], 1)), 500); return r.ok ? [r.ok.length, r.ok.map((v) => v === undefined ? 'u' : v)] : r;`, [3, ['u', 7, 'u']]),
    asyncCase('sync_throw_rejects', `return await within(settle(runPool([() => { throw new TypeError('bad input'); }, () => 2], 2)), 500);`, { err: 'bad input', type: 'TypeError' }),
    asyncCase('sync_throw_stops_later_jobs', `const jobs = mk(5); jobs[1] = () => { ctl.started.push(1); throw new Error('sync boom'); }; const P = settle(runPool(jobs, 3)); await tick(); await tick(); const r = await within(P, 300); return [r, ctl.started];`, [{ err: 'sync boom', type: 'Error' }, [0, 1]]),
    asyncCase('rejection_stops_new_jobs', `const P = settle(runPool(mk(5), 2)); await tick(); ctl.d[0].fail(new Error('boom')); await tick(); ctl.d[1].ok('late'); await tick(); await tick(); const r = await within(P, 300); return [r, ctl.started];`, [{ err: 'boom', type: 'Error' }, [0, 1]]),
    asyncCase('rejects_with_same_error_object', `const boom = new RangeError('quota'); let got; const p = runPool([() => Promise.reject(boom), () => 1], 1); try { await p; } catch (e) { got = e; } return got === boom;`, true),
    asyncCase('later_failures_ignored', `const P = settle(runPool(mk(3), 3)); await tick(); ctl.d[2].fail(new Error('first')); ctl.d[0].fail(new Error('second')); ctl.d[1].ok(1); await tick(); return await P;`, { err: 'first', type: 'Error' }),
    asyncCase('refills_slot_immediately', `const P = settle(runPool(mk(5), 2)); await tick(); ctl.d[0].ok(0); await tick(); const afterFirst = ctl.started.slice(); ctl.d[2].ok(2); await tick(); const afterSecond = ctl.started.slice(); for (const i of [1, 3, 4]) { ctl.d[i].ok(i); await tick(); } return [afterFirst, afterSecond, await P];`, [[0, 1, 2], [0, 1, 2, 3], { ok: [0, 1, 2, 3, 4] }]),
    asyncCase('starts_in_array_order', `const P = settle(runPool(mk(6), 3)); await tick(); for (const i of [2, 0, 1, 5, 4, 3]) { if (ctl.d[i]) ctl.d[i].ok(i); await tick(); } await finishAll(); await within(P, 300); return ctl.started;`, [0, 1, 2, 3, 4, 5]),
    asyncCase('limit_one_is_sequential', `const P = settle(runPool(mk(4), 1)); for (let i = 0; i < 4; i++) { await tick(); ctl.d[i].ok(i * 10); } const r = await P; return [ctl.max, r];`, [1, { ok: [0, 10, 20, 30] }]),
    asyncCase('limit_larger_than_job_count', `const P = settle(runPool(mk(3), 100)); await tick(); const s = ctl.started.length; ctl.d.forEach((d, i) => d.ok(i)); return [s, await P];`, [3, { ok: [0, 1, 2] }]),
    asyncCase('invalid_limits_rejected', `const out = []; for (const lim of [0, -1, 1.5, '2', NaN, Infinity, null, undefined]) { let r; try { r = await within(settle(runPool([() => 1], lim)), 200); } catch (e) { r = { err: 'sync', type: e && e.name }; } out.push(r && r.type); } return out;`, Array(8).fill('RangeError')),
    asyncCase('does_not_mutate_jobs', `const jobs = [() => 1, () => 2, () => 3]; const copy = jobs.slice(); await runPool(jobs, 2); return jobs.length === 3 && jobs.every((j, i) => j === copy[i]);`, true),
    asyncCase('many_microtask_jobs', `const n = 2000; let running = 0, max = 0; const jobs = Array.from({ length: n }, (_, i) => async () => { running++; max = Math.max(max, running); await null; await null; running--; return i * 2; }); const r = await runPool(jobs, 7); return [max, r.length, r[0], r[1999], r.every((v, i) => v === i * 2)];`, [7, 2000, 0, 3998, true]),
    asyncCase('fifty_thousand_plain_jobs', `const n = 50000; const jobs = Array.from({ length: n }, (_, i) => () => i); const r = await within(runPool(jobs, 3), 1500); return Array.isArray(r) ? [r.length, r[49999], r.every((v, i) => v === i)] : r;`, [50000, 49999, true]),
    asyncCase('timer_based_jobs', `const delays = [30, 5, 20, 1, 12, 8]; let running = 0, max = 0; const jobs = delays.map((ms, i) => () => new Promise((res) => { running++; max = Math.max(max, running); setTimeout(() => { running--; res('t' + i); }, ms); })); const r = await runPool(jobs, 3); return [max, r];`, [3, ['t0', 't1', 't2', 't3', 't4', 't5']]),
  ];
  return {
    slug, track: 'debug',
    title_en: 'Fix the Async Job Pool', title_ar: 'إصلاح مُجمِّع المهام غير المتزامنة',
    summary_en: 'Repair a promise pool that exceeds its concurrency limit, scrambles result order, hangs on empty input and keeps launching jobs after a failure.',
    summary_ar: 'إصلاح مجمّع وعود (Promises) يتجاوز حد التزامن ويخلط ترتيب النتائج ويتعلّق عند الإدخال الفارغ ويواصل إطلاق المهام بعد الفشل.',
    prompt: debugPrompt({
      title: 'Fix the Async Job Pool',
      context: 'A data-sync service calls a payment provider’s API through this small promise pool. The provider allows a fixed number of concurrent requests per API key.',
      code: POOL_BUGGY,
      reports: [
        'With `limit: 2` the provider still answers 429 “too many concurrent requests” — its logs show three requests in flight at once.',
        'Exported rows are shuffled: the results do not line up with the input records.',
        'The nightly sync never finishes on days when there is nothing to sync.',
        'After one request fails we still see new requests going out, and a job that returns a cached value (not a promise) crashes the pool with “then is not a function”.',
        'A config typo (`limit: 0`) was silently accepted instead of being rejected.',
      ],
    }, 'runPool'),
    output_type: 'js', difficulty: 3, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [STATIC_PARSES], tests: { entry: 'runPool', timeout_ms: 2000, weight: 9, cases: await finalizeCases(slug, 'runPool', cases) } },
  };
}

// ════════════════════════════════════════════ 5. csv parser ══════════════════════════════════════
const CSV_BUGGY = `/**
 * Parses CSV text into an array of records, each an array of string fields.
 *
 * - Fields are separated by commas; records end with LF ("\\n") or CRLF ("\\r\\n").
 *   A CR that is not followed by LF is an ordinary character.
 * - A field whose first character is a double quote is quoted: it ends at the next quote that is
 *   not doubled. Inside it, commas, CR and LF are literal and "" stands for one ". Characters after
 *   the closing quote, up to the next comma or record end, are appended to the field as they are.
 *   An unterminated quoted field extends to the end of the text.
 * - A quote anywhere else in an unquoted field is an ordinary character.
 * - Every record has at least one field: an empty line is a record with one empty field, [""].
 * - A line break at the very end of the text does not start another record; "" gives [].
 * Input can be several megabytes; parsing must take linear time.
 */
function parseCsv(text) {
  const rows = [];
  for (const line of text.split('\\n')) {
    if (line === '') continue;
    const row = [];
    let field = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (inQuotes) {
        if (ch === '"') inQuotes = false;
        else field += ch;
      } else if (ch === '"') {
        inQuotes = true;
      } else if (ch === ',') {
        row.push(field);
        field = '';
      } else {
        field += ch;
      }
    }
    if (field !== '') row.push(field);
    rows.push(row);
  }
  return rows;
}`;

async function csvTask() {
  const slug = 'debug-csv-parse';
  writeOriginal(slug, CSV_BUGGY);
  const c = (name, text, expect) => ({ name, args: [text], expect });
  const cases = [
    c('simple_rows', 'a,b,c\n1,2,3', [['a', 'b', 'c'], ['1', '2', '3']]),
    c('empty_text', '', []),
    c('single_field', 'hello', [['hello']]),
    c('final_newline_no_extra_record', 'a,b\n1,2\n', [['a', 'b'], ['1', '2']]),
    c('crlf_records', 'id,name\r\n1,Ana\r\n2,Bo\r\n', [['id', 'name'], ['1', 'Ana'], ['2', 'Bo']]),
    c('lone_cr_is_data', 'a\rb,c', [['a\rb', 'c']]),
    c('quoted_comma', 'name,city\n"Doe, Jane",Paris', [['name', 'city'], ['Doe, Jane', 'Paris']]),
    c('quoted_newline', 'id,address\n7,"12 Main St\nApt 4"\n8,x', [['id', 'address'], ['7', '12 Main St\nApt 4'], ['8', 'x']]),
    c('quoted_crlf_kept', '"line1\r\nline2",z\r\n', [['line1\r\nline2', 'z']]),
    c('doubled_quotes', '"He said ""hi""",ok', [['He said "hi"', 'ok']]),
    c('only_doubled_quote', '""""', [['"']]),
    c('trailing_empty_field', 'a,b,\n1,2,', [['a', 'b', ''], ['1', '2', '']]),
    c('trailing_empty_quoted_field', 'a,""', [['a', '']]),
    c('all_empty_fields', ',,', [['', '', '']]),
    c('empty_line_in_middle', 'a\n\nb', [['a'], [''], ['b']]),
    c('empty_line_before_final_newline', 'a\n\n', [['a'], ['']]),
    c('only_newline', '\n', [['']]),
    c('two_newlines', '\n\n', [[''], ['']]),
    c('quote_inside_unquoted_field', 'ab"c,d"e', [['ab"c', 'd"e']]),
    c('text_after_closing_quote', '"ab"cd,e', [['abcd', 'e']]),
    c('leading_space_then_quote_is_literal', ' "x",y', [[' "x"', 'y']]),
    c('unterminated_quote_runs_to_end', 'a,"bc\nd,e', [['a', 'bc\nd,e']]),
    c('unicode_fields', 'مدينة,😀\n"東京, 日本",ok', [['مدينة', '😀'], ['東京, 日本', 'ok']]),
    c('mixed_line_endings', 'a\r\nb\nc\r\n', [['a'], ['b'], ['c']]),
    { name: 'large_input_linear_time', script: `const rows = []; for (let i = 0; i < 40000; i++) rows.push(i + ',"name ' + i + ', with comma","say ""hi"" ' + i + '",'); const text = rows.join('\\r\\n') + '\\r\\n'; const r = parseCsv(text); return [r.length, r[39999], r.every((row) => row.length === 4)];`, expect: [40000, ['39999', 'name 39999, with comma', 'say "hi" 39999', ''], true] },
    { name: 'long_quoted_field', script: `const body = 'x'.repeat(300000) + '\\n' + '""'.repeat(100000); const r = parseCsv('"' + body + '",end'); return [r.length, r[0].length, r[0][0].length, r[0][1]];`, expect: [1, 2, 400001, 'end'] },
  ];
  return {
    slug, track: 'debug',
    title_en: 'Fix the CSV Parser', title_ar: 'إصلاح محلّل CSV',
    summary_en: 'Repair a CSV parser that splits quoted line breaks, mangles doubled quotes, drops empty fields and blank records, and leaves CR characters behind.',
    summary_ar: 'إصلاح محلّل CSV يقسم فواصل الأسطر داخل الحقول المقتبسة، ويُفسد علامات الاقتباس المضاعفة، ويُسقط الحقول الفارغة والسجلات الخالية، ويترك محارف CR.',
    prompt: debugPrompt({
      title: 'Fix the CSV Parser',
      context: 'A CRM imports customer lists that users export from spreadsheets (Excel on Windows, Google Sheets, LibreOffice). The import uses this parser.',
      code: CSV_BUGGY,
      reports: [
        'Customers whose address contains a line break (quoted in the file) are split into two broken rows.',
        'A note exported as `"He said ""hi"""` is imported as `He said hi` (the quotes disappear) instead of `He said "hi"`.',
        'Rows ending with an empty column lose that column (`a,b,` has 2 fields instead of 3; the same for a final `""`), which shifts columns in the preview.',
        'Files saved on Windows: the last field of every line ends with an invisible `\\r`, so e-mail addresses fail validation.',
        'Blank lines vanish, so the row numbers in our validation messages are off.',
      ],
    }, 'parseCsv'),
    output_type: 'js', difficulty: 2, visibility: 'public', auto_weight: null,
    rubric: RUBRIC,
    checks: { static: [STATIC_PARSES], tests: { entry: 'parseCsv', timeout_ms: 3000, weight: 9, cases: await finalizeCases(slug, 'parseCsv', cases) } },
  };
}

const tasks = [];
for (const make of [invoiceTask, previewTask, renewalTask, poolTask, csvTask]) tasks.push(await make());
writeTrack('debug', tasks);
