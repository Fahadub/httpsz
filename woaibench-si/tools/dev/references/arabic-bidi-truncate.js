// WB-ORACLE reference solution — arabic-bidi-truncate
// Counts extended grapheme clusters with Intl.Segmenter, trims the kept prefix, and re-closes any
// explicit embeddings/overrides (PDF) and isolates (PDI) that the cut left open, innermost first.

const FSI = '\u2068';
const PDI = '\u2069';
const PDF = '\u202C';
const ELLIPSIS = '\u2026';
const EMBEDDINGS = new Set(['\u202A', '\u202B', '\u202D', '\u202E']); // LRE RLE LRO RLO
const ISOLATES = new Set(['\u2066', '\u2067', '\u2068']); // LRI RLI FSI

function graphemes(text) {
  const seg = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
  return Array.from(seg.segment(text), (s) => s.segment);
}

function openBidiClosers(text) {
  const stack = [];
  for (const ch of text) {
    if (EMBEDDINGS.has(ch)) {
      stack.push('e');
    } else if (ISOLATES.has(ch)) {
      stack.push('i');
    } else if (ch === PDF) {
      if (stack.length && stack[stack.length - 1] === 'e') stack.pop();
    } else if (ch === PDI) {
      const at = stack.lastIndexOf('i');
      if (at >= 0) stack.length = at;
    }
  }
  return stack.reverse().map((k) => (k === 'e' ? PDF : PDI)).join('');
}

function truncateText(text, max, options) {
  const isolate = !!(options && options.isolate);
  const str = String(text == null ? '' : text);
  const parts = graphemes(str);
  let out = str;
  if (parts.length > max) {
    const prefix = parts.slice(0, Math.max(0, max - 1)).join('').replace(/\s+$/u, '');
    out = prefix + ELLIPSIS + openBidiClosers(prefix);
  }
  return isolate ? FSI + out + PDI : out;
}
