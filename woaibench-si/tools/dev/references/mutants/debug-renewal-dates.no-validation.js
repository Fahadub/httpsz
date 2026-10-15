// MUTANT: all calendar bugs fixed but no input validation (months = 0 loops forever, impossible dates accepted).
// mutant of the reference solution — debug-renewal-dates
// Pure calendar arithmetic on (year, month, day) — no Date objects, so no time-zone or overflow
// effects. Every renewal is anchored to the start date (month index + k·months, day clamped to the
// month length); validation rejects malformed input before any loop can run.

/**
 * Upcoming renewal dates of a subscription (contract in the task description).
 */
function nextRenewals(startDate, months, afterDate, count) {
  const start = parseIsoDate(startDate);
  const after = parseIsoDate(afterDate);

  const startIndex = start.y * 12 + (start.m - 1);
  const afterIndex = after.y * 12 + (after.m - 1);
  const renewal = (k) => {
    const idx = startIndex + k * months;
    const y = Math.floor(idx / 12);
    const m = (idx % 12) + 1;
    const d = Math.min(start.d, daysInMonth(y, m));
    return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  };
  // Renewals before the month of afterDate cannot qualify: jump close to it instead of looping.
  let k = 1;
  while (k > 1 && renewal(k - 1) > afterDate) k--;
  const out = [];
  for (; out.length < count; k++) {
    const r = renewal(k);
    if (r > afterDate) out.push(r);
  }
  return out;
}

function parseIsoDate(s) {
  const m = typeof s === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(s) : null;
  if (!m) throw new RangeError('expected a YYYY-MM-DD date');
  const y = Number(m[1]);
  const mo = Number(m[2]);
  const d = Number(m[3]);

  return { y, m: mo, d };
}

function daysInMonth(y, m) {
  if (m === 2) return (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0 ? 29 : 28;
  return [4, 6, 9, 11].includes(m) ? 30 : 31;
}
