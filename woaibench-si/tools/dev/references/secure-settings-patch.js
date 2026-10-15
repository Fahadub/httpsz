// WB-ORACLE reference solution — secure-settings-patch
// Builds a brand-new value from the schema outward: keys are only ever copied when the schema
// declares them (own-property lookups), dangerous names are rejected before anything else, inputs
// are never written to, and every error is collected and sorted.

const PATCH_FORBIDDEN = new Set(['__proto__', 'constructor', 'prototype']);
const PATCH_INVALID = Symbol('invalid');

/**
 * Validates `patch` against `schema` and merges it into `current` without mutating either.
 */
function applyPatch(current, patch, schema) {
  const errors = [];
  const value = mergeObject(current, patch, schema, '$', errors);
  if (errors.length) return { ok: false, errors: errors.sort() };
  return { ok: true, value };
}

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

function isPlainObject(v) {
  if (v === null || typeof v !== 'object' || Array.isArray(v)) return false;
  const proto = Object.getPrototypeOf(v);
  return proto === Object.prototype || proto === null;
}

function deepCopy(v) {
  if (Array.isArray(v)) return v.map(deepCopy);
  if (isPlainObject(v)) {
    const o = {};
    for (const k of Object.keys(v)) if (!PATCH_FORBIDDEN.has(k)) o[k] = deepCopy(v[k]);
    return o;
  }
  return v;
}

function mergeObject(cur, patch, schema, path, errors) {
  if (!isPlainObject(patch)) {
    errors.push('invalid type: ' + path);
    return PATCH_INVALID;
  }
  const out = isPlainObject(cur) ? deepCopy(cur) : {};
  for (const key of Object.keys(patch)) {
    const p = path + '.' + key;
    if (PATCH_FORBIDDEN.has(key)) { errors.push('forbidden key: ' + p); continue; }
    if (!hasOwn(schema.properties, key)) { errors.push('unknown key: ' + p); continue; }
    const sub = schema.properties[key];
    const v = patch[key];
    if (v === null) { delete out[key]; continue; }
    const r = sub.type === 'object' ? mergeObject(hasOwn(out, key) ? out[key] : undefined, v, sub, p, errors) : validateValue(v, sub, p, errors);
    if (r !== PATCH_INVALID) out[key] = r;
  }
  return out;
}

function codePointLength(s) {
  let n = 0;
  for (const _ of s) n++;
  return n;
}

function validateValue(v, schema, path, errors) {
  const fail = (kind) => { errors.push(kind + ': ' + path); return PATCH_INVALID; };
  const inRange = (x) => (schema.min === undefined || x >= schema.min) && (schema.max === undefined || x <= schema.max);
  switch (schema.type) {
    case 'string':
      if (typeof v !== 'string') return fail('invalid type');
      return schema.maxLength !== undefined && codePointLength(v) > schema.maxLength ? fail('invalid value') : v;
    case 'integer':
      if (typeof v !== 'number' || !Number.isInteger(v)) return fail('invalid type');
      return inRange(v) ? v : fail('invalid value');
    case 'number':
      if (typeof v !== 'number' || !Number.isFinite(v)) return fail('invalid type');
      return inRange(v) ? v : fail('invalid value');
    case 'boolean':
      return typeof v === 'boolean' ? v : fail('invalid type');
    case 'enum':
      return schema.values.includes(v) ? v : fail('invalid value');
    case 'array': {
      if (!Array.isArray(v)) return fail('invalid type');
      if (schema.maxItems !== undefined && v.length > schema.maxItems) return fail('invalid value');
      const out = [];
      let bad = false;
      v.forEach((item, i) => {
        const p = path + '.' + i;
        const r = validateValue(item, schema.items, p, errors);
        if (r === PATCH_INVALID) bad = true; else out.push(r);
      });
      return bad ? PATCH_INVALID : out;
    }
    case 'object':
      return mergeObject(undefined, v, schema, path, errors);
    default:
      return fail('invalid type');
  }
}
