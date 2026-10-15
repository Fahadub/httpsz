// MUTANT: the original buggy code shown in the prompt.
/**
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
 * Returns the first `count` renewals after afterDate as "YYYY-MM-DD" strings, in increasing order.
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
}
