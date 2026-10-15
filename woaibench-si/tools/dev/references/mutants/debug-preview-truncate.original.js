// MUTANT: the original buggy code shown in the prompt.
/**
 * Shortens a message for a notification preview.
 *
 * Rules (U = the text as an array of Unicode code points; "space" means U+0020):
 *  1. If U has at most maxLen code points, return the text unchanged.
 *  2. Otherwise keep the first maxLen − 1 code points (one position is reserved for "…").
 *  3. If the first dropped code point is not a space, and the kept part contains a space at a
 *     code-point index ≥ floor((maxLen − 1) / 2), cut the kept part at its LAST space
 *     (that space and everything after it are dropped).
 *  4. Remove trailing whitespace (as matched by /\s/) from the kept part and append "…" (U+2026).
 * maxLen is an integer ≥ 1. Leading whitespace is part of the message and must be preserved.
 */
function previewText(text, maxLen) {
  if (text.length <= maxLen) return text;
  let kept = text.slice(0, maxLen);
  const lastSpace = kept.lastIndexOf(' ');
  if (lastSpace > maxLen / 2) kept = kept.slice(0, lastSpace);
  return kept.trim() + '…';
}
