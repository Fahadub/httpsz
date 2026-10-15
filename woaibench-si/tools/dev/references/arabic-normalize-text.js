// WB-ORACLE reference solution — arabic-normalize-text
// NFC first, then (in this order) tashkeel/Quranic marks, tatweel, letter folding, digits, whitespace.
// Only the code points named in the specification are touched, so Latin combining marks survive.

const NORMALIZE_DEFAULTS = Object.freeze({
  diacritics: true,
  tatweel: true,
  alef: true,
  yaa: false,
  taaMarbuta: false,
  hamza: false,
  digits: 'keep',
  whitespace: true,
});

// U+0610–061A, U+064B–065F, U+0670, U+06D6–06DC, U+06DF–06E4, U+06E7–06E8, U+06EA–06ED
const ARABIC_MARKS = /[\u0610-\u061A\u064B-\u065F\u0670\u06D6-\u06DC\u06DF-\u06E4\u06E7\u06E8\u06EA-\u06ED]/g;
const TATWEEL = /\u0640/g;
const ALEF_FORMS = /[\u0622\u0623\u0625\u0671]/g; // آ أ إ ٱ
const ARABIC_INDIC = 0x0660;
const PERSIAN = 0x06f0;

function mapDigits(text, mode) {
  if (mode === 'western') {
    return text.replace(/[\u0660-\u0669\u06F0-\u06F9]/g, (d) => {
      const cp = d.codePointAt(0);
      return String(cp >= PERSIAN ? cp - PERSIAN : cp - ARABIC_INDIC);
    });
  }
  if (mode === 'arabic') {
    return text.replace(/[0-9\u06F0-\u06F9]/g, (d) => {
      const cp = d.codePointAt(0);
      const n = cp >= PERSIAN ? cp - PERSIAN : cp - 48;
      return String.fromCodePoint(ARABIC_INDIC + n);
    });
  }
  return text;
}

function normalizeArabic(text, options) {
  const o = Object.assign({}, NORMALIZE_DEFAULTS, options || {});
  let s = String(text == null ? '' : text).normalize('NFC');
  if (o.diacritics) s = s.replace(ARABIC_MARKS, '');
  if (o.tatweel) s = s.replace(TATWEEL, '');
  if (o.alef) s = s.replace(ALEF_FORMS, '\u0627');
  if (o.yaa) s = s.replace(/\u0649/g, '\u064A');
  if (o.taaMarbuta) s = s.replace(/\u0629/g, '\u0647');
  if (o.hamza) s = s.replace(/\u0624/g, '\u0648').replace(/\u0626/g, '\u064A');
  s = mapDigits(s, o.digits);
  if (o.whitespace) s = s.replace(/\s+/gu, ' ').trim();
  return s;
}
