// MUTANT (trusts Intl "ar" defaults (Latin digits on modern ICU)) of the reference solution — arabic-plural-numbers
// Formatting is done by hand (not Intl) because ICU builds disagree on the default numbering system
// for "ar"; plural categories follow CLDR: zero, one, two, few (n%100 3–10), many (n%100 11–99), other.

const AR_DIGITS = '\u0660\u0661\u0662\u0663\u0664\u0665\u0666\u0667\u0668\u0669'; // ٠١٢٣٤٥٦٧٨٩
const AR_GROUP = '\u066C'; // ٬ Arabic thousands separator
const AR_DECIMAL = '\u066B'; // ٫ Arabic decimal separator

function toArabicDigits(s) {
  return s.replace(/[0-9]/g, (d) => AR_DIGITS[d.charCodeAt(0) - 48]);
}

function formatNumberAr(n) {
  return new Intl.NumberFormat('ar').format(n);
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
