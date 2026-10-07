// بصير — عامل الخدمة: يحفظ واجهة التطبيق ليفتح بسرعة ويُثبَّت كتطبيق. طلبات api.php لا تُخزَّن أبداً.
const CACHE = 'basir-v1';
const SHELL = [
  './', 'index.html', 'setup.html', 'cam.html', 'config.js', 'manifest.webmanifest',
  'assets/app.css', 'assets/core.js', 'assets/app.js', 'assets/commands.js', 'assets/setup.js', 'assets/cam.js',
  'assets/icons/icon.svg', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icons/maskable-512.png',
  'assets/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL.map((p) => new URL(p, self.registration.scope))))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('basir-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// الشبكة أولاً (لتصل التحديثات فوراً)، والنسخة المحفوظة عند انقطاع الاتصال بالخادم
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.endsWith('/api.php')) return;
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() =>
        caches.match(req, { ignoreSearch: true }).then((hit) =>
          hit || (req.mode === 'navigate' ? caches.match(new URL('index.html', self.registration.scope)) : Response.error())
        )
      )
  );
});
