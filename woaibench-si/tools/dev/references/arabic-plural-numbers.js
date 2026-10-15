// WB-ORACLE reference solution — arabic-plural-numbers
// Formatting is done by hand (not Intl) because ICU builds disagree on the default numbering system
// for "ar"; plural categories follow CLDR: zero, one, two, few (n%100 3–10), many (n%100 11–99), other.

const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩';
const AR_GROUP = '٬'; // ٬
const AR_DECIMAL = '٫'; // ٫

function toArabicDigits(s) {
  return s.replace(/[0-9]/g, (d) => AR_DIGITS[d.charCodeAt(0) - 48]);
}

function formatNumberAr(n) {
  const [int, frac] = String(n).split('.');
  const grouped = int.length > 3 ? int.replace(/\B(?=(\d{3})+(?!\d))/g, AR_GROUP) : int;
  return toArabicDigits(grouped) + (frac !== undefined ? AR_DECIMAL + toArabicDigits(frac) : '');
}

function pluralCategoryAr(n) {
  if (n === 0) return 'zero';
  if (n === 1) return 'one';
  if (n === 2) return 'two';
  if (!Number.isInteger(n)) return 'other';
  const r = n % 100;
  if (r >= 3 && r <= 10) return 'few';
  if (r >= 11 && r <= 99) return 'many';
  return 'other';
}

function pluralAr(n, forms) {
  const cat = pluralCategoryAr(n);
  const template = Object.prototype.hasOwnProperty.call(forms, cat) ? forms[cat] : forms.other;
  return String(template).split('#').join(formatNumberAr(n));
}
