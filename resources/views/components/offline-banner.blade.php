{{--
    Generic "you're offline" / "N changes pending sync" banner.
    Foundational infrastructure (blueprint §45-46) — include this on any
    page that lets a user queue writes via window.OfflineQueue
    (resources/js/offline-queue.js). Purely presentational + a thin
    online/offline + OfflineQueue.pendingCount() listener; no feature-specific
    logic lives here.

    Usage: <x-offline-banner />
--}}
<div
    id="offline-banner"
    role="status"
    aria-live="polite"
    hidden
    class="fixed inset-x-0 bottom-0 z-50 flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-center"
    style="background:#1b3425;color:#f5f0e6;padding-bottom:calc(0.625rem + env(safe-area-inset-bottom));"
>
    <span id="offline-banner-dot" class="inline-block h-2 w-2 rounded-full" style="background:#cb9248;"></span>
    <span id="offline-banner-text">You're offline</span>
</div>

<script>
(() => {
    const banner = document.getElementById('offline-banner');
    const text = document.getElementById('offline-banner-text');
    if (!banner || !text) return;

    async function render() {
        const offline = !navigator.onLine;
        let pending = 0;
        try {
            if (window.OfflineQueue && typeof window.OfflineQueue.pendingCount === 'function') {
                pending = await window.OfflineQueue.pendingCount();
            }
        } catch (e) {}

        if (!offline && pending === 0) {
            banner.hidden = true;
            return;
        }

        banner.hidden = false;
        if (offline && pending > 0) {
            text.textContent = `You're offline — ${pending} change${pending === 1 ? '' : 's'} pending sync`;
        } else if (offline) {
            text.textContent = "You're offline";
        } else {
            text.textContent = `${pending} change${pending === 1 ? '' : 's'} pending sync`;
        }
    }

    window.addEventListener('online', render);
    window.addEventListener('offline', render);

    // OfflineQueue notifies successful syncs; re-render the count then.
    if (window.OfflineQueue && typeof window.OfflineQueue.onSync === 'function') {
        window.OfflineQueue.onSync(render);
    }

    // Poll lightly too, since enqueue() can happen from any feature module
    // without this banner being notified directly.
    setInterval(render, 5000);

    render();
})();
</script>
