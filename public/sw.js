// Cameroon Timber Hub — app-shell service worker (hand-rolled, no Workbox).
//
// Also implements the generic offline-write-queue replay side (blueprint
// §45-46). Feature code never talks to this file directly — it goes
// through resources/js/offline-queue.js's OfflineQueue.enqueue()/flush().
// This worker only: (a) wakes on the Background Sync `sync` event (tag
// "offline-queue-sync") where supported, and replays whatever is sitting
// in the same IndexedDB queue, removing each item only on a real 2xx.
// Browsers without Background Sync (Safari/iOS) never fire that event —
// for them OfflineQueue's own `online` listener + manual flush() is the
// entire fallback path, so this worker doing nothing there is expected,
// not a bug.
const CACHE = 'cth-shell-v2';
const PRECACHE = ['/', '/offline.html'];

const QUEUE_DB_NAME = 'cth-offline-queue';
const QUEUE_DB_VERSION = 1;
const QUEUE_STORE = 'requests';

function openQueueDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(QUEUE_DB_NAME, QUEUE_DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(QUEUE_STORE)) {
                db.createObjectStore(QUEUE_STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function getQueuedItems() {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE, 'readonly');
        const req = tx.objectStore(QUEUE_STORE).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

async function removeQueuedItem(id) {
    const db = await openQueueDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(QUEUE_STORE, 'readwrite');
        tx.objectStore(QUEUE_STORE).delete(id);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function notifyClients(message) {
    const clientsList = await self.clients.matchAll({ type: 'window' });
    clientsList.forEach((c) => c.postMessage(message));
}

async function replayQueue() {
    const items = await getQueuedItems();
    for (const item of items) {
        try {
            const headers = Object.assign({ 'Content-Type': 'application/json' }, item.headers || {});
            const resp = await fetch(item.url, {
                method: item.method || 'POST',
                headers,
                body: item.body != null ? JSON.stringify(item.body) : undefined,
                credentials: 'same-origin',
            });
            if (resp && resp.ok) {
                await removeQueuedItem(item.id);
                await notifyClients({ type: 'offline-queue-synced', id: item.id, url: item.url, method: item.method });
            }
            // Non-2xx: leave it queued, keep trying the rest.
        } catch (e) {
            // Offline again — stop, wait for the next sync/flush attempt.
            break;
        }
    }
}

// Background Sync (Chrome/Edge/Android). Safari/iOS never dispatch this —
// OfflineQueue's own online-listener fallback covers those browsers.
self.addEventListener('sync', (event) => {
    if (event.tag === 'offline-queue-sync') {
        event.waitUntil(replayQueue());
    }
});

// Manual fallback trigger from the page (works everywhere, including
// browsers without Background Sync): postMessage({ type: 'FLUSH_QUEUE' }).
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'FLUSH_QUEUE') {
        event.waitUntil(replayQueue());
    }
});

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
