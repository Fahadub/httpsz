// MUTANT: host check uses endsWith(host) without the dot (evilexample.test passes).
// mutant of the reference solution — secure-redirect-guard
// Parse exactly like the browser will (WHATWG URL relative to the app origin), then decide on the
// parsed components only: credentials, scheme, port and an exact/sub-domain host match.

/**
 * Returns a safe redirect location for an untrusted `target`, or "/" when it must be refused.
 */
function safeRedirect(target, appOrigin, allowedHosts) {
  if (typeof target !== 'string' || target.length > 2048) return '/';
  let url;
  try {
    url = new URL(target, appOrigin + '/');
  } catch (e) {
    return '/';
  }
  if (url.username !== '' || url.password !== '') return '/';
  if (url.protocol + '//' + url.host === appOrigin) {
    const local = url.pathname + url.search + url.hash;
    return local.startsWith('//') ? url.href : local;
  }
  if (url.protocol !== 'https:' || url.port !== '') return '/';
  const host = url.hostname;
  const ok = Array.isArray(allowedHosts) && allowedHosts.some((h) => typeof h === 'string' && h !== '' && host.endsWith(h));
  return ok ? url.href : '/';
}
