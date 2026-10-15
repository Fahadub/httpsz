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
const ARABIC_MARKS = /[ؐ-ًؚ-ٰٟۖ-ۜ۟-۪ۤۧۨ-ۭ]/g;
const TATWEEL = /ـ/g;
const ALEF_FORMS = /[آأإٱ]/g; // آ أ إ ٱ
const ARABIC_INDIC = 0x0660;
const PERSIAN = 0x06f0;

function mapDigits(text, mode) {
  if (mode === 'western') {
    return text.replace(/[٠-٩۰-۹]/g, (d) => {
      const cp = d.codePointAt(0);
      return String(cp >= PERSIAN ? cp - PERSIAN : cp - ARABIC_INDIC);
    });
  }
  if (mode === 'arabic') {
    return text.replace(/[0-9۰-۹]/g, (d) => {
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
  if (o.alef) s = s.replace(ALEF_FORMS, 'ا');
  if (o.yaa) s = s.replace(/ى/g, 'ي');
  if (o.taaMarbuta) s = s.replace(/ة/g, 'ه');
  if (o.hamza) s = s.replace(/ؤ/g, 'و').replace(/ئ/g, 'ي');
  s = mapDigits(s, o.digits);
  if (o.whitespace) s = s.replace(/\s+/gu, ' ').trim();
  return s;
}
