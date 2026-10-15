// MUTANT: follows links without a hop limit (loops never end).
// mutant of the reference solution — secure-vfs-resolve
// Decode once → reject control characters / backslashes → normalise lexically (deny, never clamp,
// on climbing above the root) → resolve physically, following links with a hop budget → check the
// REAL path against the root with a separator-aware prefix test and the hidden-segment rule.

const VFS_MAX_HOPS = 40;

/**
 * Maps an untrusted, percent-encoded request path to the real path of a file inside `root`, or null.
 */
function resolveSafe(fs, root, requestPath) {
  if (typeof requestPath !== 'string' || requestPath.length > 4096) return null;
  let decoded;
  try {
    decoded = decodeURIComponent(requestPath);
  } catch (e) {
    return null;
  }
  if (/[\u0000-\u001F\u007F\\]/.test(decoded)) return null;
  const segments = [];
  for (const part of decoded.split('/')) {
    if (part === '' || part === '.') continue;
    if (part === '..') {
      if (segments.length === 0) return null;
      segments.pop();
    } else segments.push(part);
  }
  if (segments.some((s) => s.startsWith('.'))) return null;

  const real = walk(fs, root, segments);
  if (real === null || !isInside(real, root)) return null;
  const below = root === '/' ? real.slice(1) : real.slice(root.length + 1);
  if (below.split('/').some((s) => s.startsWith('.'))) return null;
  const entry = lookup(fs, real);
  return entry && entry.type === 'file' ? real : null;
}

function lookup(fs, p) {
  return Object.prototype.hasOwnProperty.call(fs, p) ? fs[p] : undefined;
}

function parentOf(p) {
  const i = p.lastIndexOf('/');
  return i <= 0 ? '/' : p.slice(0, i);
}

function isInside(p, root) {
  return root === '/' || p === root || p.startsWith(root + '/');
}

/** Physical resolution: '..' inside link targets moves to the parent of the current real directory. */
function walk(fs, start, names) {
  const pending = names.slice().reverse(); // next name to visit is at the end
  let cur = start;
  let hops = 0;
  while (pending.length) {
    const name = pending.pop();
    if (name === '' || name === '.') continue;
    const here = lookup(fs, cur);
    if (!here || here.type !== 'dir') return null;
    if (name === '..') { cur = parentOf(cur); continue; }
    const next = cur === '/' ? '/' + name : cur + '/' + name;
    const entry = lookup(fs, next);
    if (!entry) return null;
    if (entry.type === 'link') {
      const target = String(entry.target);
      const parts = target.split('/');
      for (let i = parts.length - 1; i >= 0; i--) pending.push(parts[i]);
      if (target.startsWith('/')) cur = '/';
      continue;
    }
    cur = next;
  }
  return cur;
}
