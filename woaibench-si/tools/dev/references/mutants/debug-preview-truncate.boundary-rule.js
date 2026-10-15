// MUTANT: all bugs fixed except the word-boundary rule (ignores the first dropped code point, uses > maxLen / 2).
// mutant of the reference solution — debug-preview-truncate
// Works on an array of code points; reserves one position for the ellipsis; honours the
// "first dropped code point is a space" rule and the floor((maxLen − 1) / 2) threshold; trims only
// trailing whitespace.

/**
 * Shortens a message for a notification preview (rules in the task contract).
 */
function previewText(text, maxLen) {
  const cps = Array.from(text);
  if (cps.length <= maxLen) return text;
  const k = maxLen - 1;
  let kept = cps.slice(0, k);
  const lastSpace = kept.lastIndexOf(' ');
  if (lastSpace > maxLen / 2) kept = kept.slice(0, lastSpace);
  return kept.join('').replace(/\s+$/u, '') + '…';
}
