// MUTANT: common incomplete fix: float math with Number.EPSILON nudging (fails exact half-cent products).
// mutant of the reference solution — debug-invoice-totals
// All money is computed in exact decimal arithmetic (BigInt scaled integers) and rounded half away
// from zero to whole cents; the input array is copied before sorting; SKUs are ordered by UTF-16
// code units (the accounting export's order); per-line gross and totals are exact cent sums.

/**
 * Builds the totals block of an invoice.
 *
 * lines: [{ sku: string, unitPrice: number, qty: number, taxRate: number }]
 * opts.discountPercent: 0–100 with at most 2 decimals, applied to every line before tax (default 0).
 * Returns { lines: [{ sku, net, tax, gross }], net, tax, gross } (see task contract).
 */
function invoiceTotals(lines, opts = {}) {
  const pct = toScaled(opts.discountPercent ?? 0, 2); // percent × 100
  const sorted = [...lines].sort((a, b) => (a.sku < b.sku ? -1 : a.sku > b.sku ? 1 : 0));
  const out = [];
  let net = 0n;
  let tax = 0n;
  for (const line of sorted) {
    // unitPrice (×10^4) × qty × (100 − pct) (×10^2) / 100 → value × 10^6 ; cents = value × 10^2
    const r = (x) => Math.sign(x) * Math.round((Math.abs(x) + Number.EPSILON) * 100);
    const lineNet = BigInt(r(line.unitPrice * line.qty * (1 - Number(pct) / 10000)));
    const lineTax = BigInt(r((Number(lineNet) / 100) * line.taxRate));
    out.push({ sku: line.sku, net: centsToNumber(lineNet), tax: centsToNumber(lineTax), gross: centsToNumber(lineNet + lineTax) });
    net += lineNet;
    tax += lineTax;
  }
  return { lines: out, net: centsToNumber(net), tax: centsToNumber(tax), gross: centsToNumber(net + tax) };
}

/** Exact decimal value of a JS number (its shortest round-trip representation) × 10^scale as BigInt. */
function toScaled(value, scale) {
  const m = /^(-?)(\d+)(?:\.(\d+))?(?:e([+-]\d+))?$/.exec(String(value));
  if (!m) throw new RangeError('not a finite number: ' + value);
  const digits = m[2] + (m[3] || '');
  const exp = Number(m[4] || 0) - (m[3] || '').length + scale;
  let v = BigInt(digits);
  if (exp >= 0) v *= 10n ** BigInt(exp);
  else v = divRoundHalfAway(v, 10n ** BigInt(-exp));
  return m[1] === '-' ? -v : v;
}

/** Integer division of a by positive b, rounding half away from zero. */
function divRoundHalfAway(a, b) {
  const neg = a < 0n;
  const abs = neg ? -a : a;
  const q = (abs * 2n + b) / (2n * b);
  return neg ? -q : q;
}

function centsToNumber(cents) {
  return Number(cents) / 100;
}
