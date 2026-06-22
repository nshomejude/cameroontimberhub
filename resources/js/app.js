// Native app-shell behaviors for the Cameroon Timber Hub PWA.

// 1. Service worker (PWA install + offline).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

// 2. Install-to-home-screen prompt.
let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    window.dispatchEvent(new CustomEvent('pwa-installable'));
});
window.__installPwa = async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    await deferredPrompt.userChoice.catch(() => {});
    deferredPrompt = null;
};

// 3. SPA navigation: intercept internal <a> clicks and route through Livewire's
//    navigate (instant, no full reload) when available.
document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a');
    if (!a) return;

    const href = a.getAttribute('href');
    if (!href || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-native-ignore')) return;
    if (href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return;

    let url;
    try { url = new URL(href, location.href); } catch { return; }
    if (url.origin !== location.origin) return;
    // Let the authenticated panels do real navigations.
    if (url.pathname.startsWith('/admin') || url.pathname.startsWith('/dashboard')) return;

    if (window.Livewire && typeof window.Livewire.navigate === 'function') {
        e.preventDefault();
        window.Livewire.navigate(url.href);
    }
});

// 4. Re-trigger the page enter transition + reset scroll on each navigation.
document.addEventListener('livewire:navigated', () => {
    const main = document.getElementById('app-main');
    if (main) {
        main.classList.remove('app-page');
        void main.offsetWidth; // force reflow so the animation replays
        main.classList.add('app-page');
    }
    window.scrollTo({ top: 0 });
});

// 5. Swipe from the left edge to go back (native gesture).
(() => {
    let startX = 0, startY = 0, tracking = false;
    window.addEventListener('touchstart', (e) => {
        const t = e.touches[0];
        if (t.clientX <= 24) { tracking = true; startX = t.clientX; startY = t.clientY; }
    }, { passive: true });
    window.addEventListener('touchend', (e) => {
        if (!tracking) return;
        tracking = false;
        const t = e.changedTouches[0];
        if (t.clientX - startX > 80 && Math.abs(t.clientY - startY) < 60) {
            history.back();
        }
    }, { passive: true });
})();

// 6. Pull-to-refresh at the top of the page.
(() => {
    const indicator = () => document.getElementById('pull-indicator');
    let startY = 0, pulling = false;
    window.addEventListener('touchstart', (e) => {
        if (window.scrollY <= 0) { startY = e.touches[0].clientY; pulling = true; }
    }, { passive: true });
    window.addEventListener('touchmove', (e) => {
        if (!pulling) return;
        const dy = e.touches[0].clientY - startY;
        const ind = indicator();
        if (dy > 0 && window.scrollY <= 0 && ind) {
            ind.style.opacity = Math.min(1, dy / 90);
            ind.style.transform = `translateY(${Math.min(dy / 2, 56)}px)`;
        }
    }, { passive: true });
    window.addEventListener('touchend', (e) => {
        if (!pulling) return;
        pulling = false;
        const dy = e.changedTouches[0].clientY - startY;
        const ind = indicator();
        if (ind) { ind.style.opacity = ''; ind.style.transform = ''; }
        if (dy > 100 && window.scrollY <= 0) {
            if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                window.Livewire.navigate(location.href);
            } else {
                location.reload();
            }
        }
    }, { passive: true });
})();
