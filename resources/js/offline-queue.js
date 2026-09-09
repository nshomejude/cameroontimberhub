/**
 * OfflineQueue — generic, framework-agnostic offline write queue.
 * =================================================================
 * FOUNDATIONAL INFRASTRUCTURE (blueprint §45-46). Any feature module that
 * needs to let a user submit a POST while offline (or on flaky connectivity)
 * should use ONLY the public API below — no other agent/feature should need
 * to touch public/sw.js directly.
 *
 * Persistence: an IndexedDB database `cth-offline-queue` (store `requests`),
 * so queued items survive a page reload / app restart. Replay is attempted:
 *   1. via the Background Sync API (tag `offline-queue-sync`) when the
 *      browser supports it (`navigator.serviceWorker.ready` +
 *      `registration.sync`) — the service worker wakes up and flushes the
 *      queue even if the tab is closed.
 *   2. FALLBACK for browsers without Background Sync (notably Safari/iOS):
 *      this module also listens for the `online` event and calls
 *      `OfflineQueue.flush()` itself, and `flush()` can always be invoked
 *      manually (e.g. from a "Retry sync" button) — it does not require
 *      Background Sync support.
 * An item is removed from the queue only after a *real* 2xx HTTP response;
 * network errors, opaque responses, or non-2xx statuses leave it queued.
 *
 * PUBLIC API
 * ----------
 *   OfflineQueue.enqueue(url, method, body, headers = {}) -> Promise<id>
 *       Persists a pending write (default method 'POST') to IndexedDB and
 *       schedules a sync attempt (Background Sync if available, otherwise
 *       an immediate best-effort flush() if currently online). `body` may
 *       be any JSON-serializable value or a plain object (form-friendly);
 *       it is stored as JSON and replayed with
 *       `Content-Type: application/json` merged with any `headers` you pass
 *       (e.g. add your CSRF token header here — this module does not know
 *       about Laravel's CSRF scheme).
 *
 *   OfflineQueue.flush() -> Promise<{ synced: number, remaining: number }>
 *       Manually attempts to replay every queued item, in insertion order,
 *       against the network. Use this as the universal fallback path (call
 *       it from an "online" handler, a visible "Retry" button, etc.) — it
 *       works in every browser, with or without Background Sync.
 *
 *   OfflineQueue.onSync(callback) -> unsubscribe()
 *       Registers `callback({ id, url, method, ok })` fired once per item
 *       right after a successful (2xx) replay, whether that replay was
 *       driven by the service worker's `sync` event or by flush(). Use it
 *       to update UI (e.g. remove an optimistic row, show a toast).
 *
 *   OfflineQueue.pendingCount() -> Promise<number>
 *       Resolves with the number of items currently queued (not yet
 *       synced). Used by the offline banner partial
 *       (resources/views/components/offline-banner.blade.php) but safe to
 *       call from any feature UI.
 *
 * This module does NOT render any UI and does NOT know about any specific
 * feature's payload shape — it is pure transport plumbing.
 * =================================================================
 */

const DB_NAME = 'cth-offline-queue';
const DB_VERSION = 1;
const STORE = 'requests';
const SYNC_TAG = 'offline-queue-sync';

/** @type {Array<(evt: {id:number, url:string, method:string, ok:boolean}) => void>} */
const syncListeners = [];

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function withStore(mode, fn) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const store = tx.objectStore(STORE);
        const result = fn(store);
        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(tx.error);
    });
}

function getAllItems() {
    return withStore('readonly', (store) => {
        return new Promise((resolve, reject) => {
            const req = store.getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    }).then((p) => p);
}

async function enqueue(url, method = 'POST', body = null, headers = {}) {
    const item = { url, method, body, headers, createdAt: Date.now() };
    const id = await withStore('readwrite', (store) => {
        return new Promise((resolve, reject) => {
            const req = store.add(item);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    });

    // Prefer Background Sync (works even if the tab later closes).
    let registeredSync = false;
    try {
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            const reg = await navigator.serviceWorker.ready;
            if (reg.sync) {
                await reg.sync.register(SYNC_TAG);
                registeredSync = true;
            }
        }
    } catch (e) {
        registeredSync = false;
    }

    // Fallback: if Background Sync isn't available (Safari/iOS) and we're
    // online right now, attempt an immediate flush ourselves.
    if (!registeredSync && navigator.onLine) {
        flush().catch(() => {});
    }

    return id;
}

async function removeItem(id) {
    return withStore('readwrite', (store) => {
        return new Promise((resolve, reject) => {
            const req = store.delete(id);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    });
}

async function replayOne(item) {
    const headers = Object.assign({ 'Content-Type': 'application/json' }, item.headers || {});
    const resp = await fetch(item.url, {
        method: item.method || 'POST',
        headers,
        body: item.body != null ? JSON.stringify(item.body) : undefined,
        credentials: 'same-origin',
    });
    return resp;
}

async function flush() {
    const items = await getAllItems();
    let synced = 0;
    for (const item of items) {
        try {
            const resp = await replayOne(item);
            if (resp && resp.ok) {
                await removeItem(item.id);
                synced += 1;
                syncListeners.forEach((cb) => {
                    try { cb({ id: item.id, url: item.url, method: item.method, ok: true }); } catch (e) {}
                });
            }
            // Non-2xx: leave queued, try the next item.
        } catch (e) {
            // Network error: stop here, the rest are almost certainly
            // unreachable too — try again on the next flush/sync.
            break;
        }
    }
    const remaining = (await getAllItems()).length;
    return { synced, remaining };
}

function onSync(callback) {
    syncListeners.push(callback);
    return () => {
        const idx = syncListeners.indexOf(callback);
        if (idx !== -1) syncListeners.splice(idx, 1);
    };
}

async function pendingCount() {
    const items = await getAllItems();
    return items.length;
}

// Universal fallback: whenever the browser regains connectivity, try to
// flush. This is what makes the queue work on browsers without Background
// Sync (Safari/iOS) — no service-worker involvement required.
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        flush().catch(() => {});
    });

    // Also react to the service worker telling us it successfully synced
    // an item via the real `sync` event (Chrome/Edge/Android), so onSync()
    // subscribers get notified even when this module itself wasn't the one
    // driving the replay.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            const data = event.data || {};
            if (data.type === 'offline-queue-synced') {
                syncListeners.forEach((cb) => {
                    try { cb({ id: data.id, url: data.url, method: data.method, ok: true }); } catch (e) {}
                });
            }
        });
    }
}

export const OfflineQueue = { enqueue, flush, onSync, pendingCount };

if (typeof window !== 'undefined') {
    window.OfflineQueue = OfflineQueue;
}
