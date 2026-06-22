// Cameroon Timber Hub — app-shell service worker (hand-rolled, no Workbox).
const CACHE = 'cth-shell-v1';
const PRECACHE = ['/', '/offline.html'];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE).catch(() => {})));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    // Never intercept the authenticated panels.
    if (url.pathname.startsWith('/admin') || url.pathname.startsWith('/dashboard')) return;

    // Page navigations: network-first, fall back to a cached offline shell.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then((resp) => {
                    const copy = resp.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
                    return resp;
                })
                .catch(() => caches.match(req).then((r) => r || caches.match('/offline.html')))
        );
        return;
    }

    // Static assets (built bundles, icons, fonts): cache-first.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.includes('/fonts')) {
        event.respondWith(
            caches.match(req).then((cached) => cached || fetch(req).then((resp) => {
                const copy = resp.clone();
                caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
                return resp;
            }))
        );
    }
});
