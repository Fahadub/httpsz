// MUTANT: attr context leaves single quotes and backticks raw.
// mutant of the reference solution — secure-context-encoder
// Allow-list encoders: only characters known to be inert in a context are left as they are,
// everything else is replaced by a reference/escape that cannot terminate the context.

const ENC_HTML = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const ENC_ATTR = { ...ENC_HTML, '`': '&#96;' };

/**
 * Encodes `value` for safe insertion into one HTML-page context: "html" | "attr" | "js" | "url".
 */
function encodeFor(context, value) {
  const s = toWellFormedText(value);
  switch (context) {
    case 'html':
      return s.replace(/[&<>"']/g, (c) => ENC_HTML[c]);
    case 'attr':
      return s.replace(/[&<>"]/g, (c) => ENC_ATTR[c]);
    case 'js':
      return escapeJsString(s);
    case 'url':
      return percentEncode(s);
    default:
      throw new TypeError('unknown context: ' + String(context));
  }
}

function toWellFormedText(value) {
  if (value === null || value === undefined) return '';
  const t = typeof value;
  if (t !== 'string' && t !== 'number' && t !== 'boolean' && t !== 'bigint') throw new TypeError('unsupported value type: ' + t);
  // Replace lone surrogates with U+FFFD.
  return String(value).replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g, '�');
}

const JS_INERT = new Uint8Array(128);
for (const ch of 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 ,.:;_-+*!?#%()[]|~^@') JS_INERT[ch.charCodeAt(0)] = 1;

/** Output is printable ASCII only: letters, digits, space and a few inert punctuation marks stay; the rest becomes \xHH or \uHHHH. */
function escapeJsString(s) {
  const parts = [];
  for (let i = 0; i < s.length; i++) {
    const code = s.charCodeAt(i);
    if (code < 128 && JS_INERT[code] === 1) parts.push(s[i]);
    else if (code < 0x100) parts.push('\\x' + (code < 16 ? '0' : '') + code.toString(16).toUpperCase());
    else parts.push('\\u' + code.toString(16).toUpperCase().padStart(4, '0'));
  }
  return parts.join('');
}

function percentEncode(s) {
  const bytes = new TextEncoder().encode(s);
  let out = '';
  for (const b of bytes) {
    const c = String.fromCharCode(b);
    out += /[A-Za-z0-9\-._~]/.test(c) && b < 0x80 ? c : '%' + b.toString(16).toUpperCase().padStart(2, '0');
  }
  return out;
}
