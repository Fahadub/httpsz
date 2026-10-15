// MUTANT: the original buggy code shown in the prompt.
/**
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
}
