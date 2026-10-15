// MUTANT: re-renders the output until it stops changing (data can inject tags).
// mutant of the reference solution — secure-template-render
// One left-to-right pass: a sticky regex tries to read a tag at each "{{" (bounded, no backtracking
// blow-up); substituted values are never re-scanned; lookups only follow own properties of plain
// objects/arrays and never the names __proto__ / constructor / prototype.

const TPL_TAG = /\{\{[ \t\r\n]*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)[ \t\r\n]*(?:\|[ \t\r\n]*([A-Za-z_][A-Za-z0-9_]*)[ \t\r\n]*)?\}\}/y;
const TPL_FORBIDDEN = new Set(['__proto__', 'constructor', 'prototype']);
const TPL_HTML = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/**
 * Renders `{{ path }}` / `{{ path | filter }}` placeholders of a trusted template with untrusted data.
 */
function renderTemplate(template, data) {
  for (let n = 0; n < 5; n++) { const next = renderOnce(template, data); if (next === template) break; template = next; }
  return template;
}
function renderOnce(template, data) {
  let out = '';
  let i = 0;
  while (i < template.length) {
    const j = template.indexOf('{{', i);
    if (j === -1) { out += template.slice(i); break; }
    out += template.slice(i, j);
    TPL_TAG.lastIndex = j;
    const m = TPL_TAG.exec(template);
    if (!m) { out += '{'; i = j + 1; continue; }
    out += renderValue(lookupPath(data, m[1].split('.')), m[2]);
    i = TPL_TAG.lastIndex;
  }
  return out;
}

const TPL_MISSING = Symbol('missing');

function lookupPath(data, keys) {
  let cur = data;
  for (const k of keys) {
    if (TPL_FORBIDDEN.has(k) || cur === null || typeof cur !== 'object' || !Object.prototype.hasOwnProperty.call(cur, k)) return TPL_MISSING;
    cur = cur[k];
  }
  return cur;
}

function toText(v) {
  const t = typeof v;
  return t === 'string' ? v : (t === 'number' || t === 'bigint' || t === 'boolean' ? String(v) : '');
}

function escapeHtml(s) {
  return s.replace(/[&<>"']/g, (c) => TPL_HTML[c]);
}

function renderValue(value, filter) {
  const v = value === TPL_MISSING ? undefined : value;
  switch (filter) {
    case undefined: return escapeHtml(toText(v));
    case 'upper': return escapeHtml(toText(v).toUpperCase());
    case 'lower': return escapeHtml(toText(v).toLowerCase());
    case 'trim': return escapeHtml(toText(v).trim());
    case 'json': return scriptSafeJson(v);
    default: return '';
  }
}

function scriptSafeJson(v) {
  let json;
  try {
    json = JSON.stringify(v);
  } catch (e) {
    json = undefined;
  }
  if (json === undefined) json = 'null';
  return json.replace(/[<>&\u2028\u2029]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
}
