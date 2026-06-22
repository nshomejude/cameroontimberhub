---
title: Cameroon Timber Hub — Native Mobile App Shell (PWA)
date: 2026-06-22
status: approved-for-prototype
---

# Native mobile app shell

Turn the public-facing frontend into an installable, app-like experience that
behaves like a native mobile app — using the existing TALL stack (no new
framework). Approved direction: **installable PWA · bottom tab bar + contextual
top bar · core + advanced gestures · unified adaptive app shell on all screen
sizes** (this replaces the editorial desktop layout for public pages).

Scope: the **public frontend only** (home, directory, company profile, species,
request-a-quote, account/login). The Filament `/admin` and `/dashboard` panels
are out of scope — they already ship a responsive mobile UI.

## Approach

TALL-native app shell:
- **Livewire `wire:navigate`** for instant SPA page swaps (no reload / white
  flash), with the shell **`@persist`ed** so it never re-renders; history +
  scroll position preserved. Livewire JS is loaded app-wide via `@livewireScripts`
  so `wire:navigate` works on the existing plain-Blade pages (no need to convert
  pages to Livewire components).
- **Alpine** for gestures (swipe-back, pull-to-refresh) and bottom sheets.
- A hand-rolled **PWA** (manifest + service worker), no Workbox dependency.
- Existing forest/timber/sand design tokens reused; app-shell components added.

## Architecture

A single persistent shell (`components/layouts/app-shell.blade.php`):
- **Top app bar** — contextual screen title + a back button when the screen is a
  detail/deep view; hidden actions slot.
- **Main** — momentum-scrolling routed page (the existing controllers/views).
- **Bottom tab bar** (mobile) / **left nav rail** (desktop, ≥ md) — same five
  destinations, one component, adaptive: Home · Exporters · Species · Quote ·
  Account. Active tab highlighted; thumb-reachable on mobile.
- Safe-area insets via `env(safe-area-inset-*)`; `100dvh` height; the shell sits
  inside `@persist` blocks so `wire:navigate` keeps it mounted.

## Native interactions

1. **Instant navigation** — `wire:navigate` on all internal links; scroll + focus
   restoration; a top progress / skeleton during fetch.
2. **Slide page transitions** — direction-aware (forward = push left, back = pop
   right). Implemented by listening to Livewire navigate events and animating the
   incoming main content with CSS keyframes; back direction detected via
   `popstate`. (View Transitions API used where supported; CSS-keyframe fallback
   otherwise.)
3. **Swipe-from-left-edge → back** — Alpine touch handler on the shell; past a
   threshold triggers `history.back()`.
4. **Pull-to-refresh** — Alpine handler on the scroll container; pulling past a
   threshold at scrollTop 0 reloads the current route via `Livewire.navigate`.
5. **Bottom-sheet / action-sheet modals** — a reusable `x-bottom-sheet` Alpine
   component (backdrop, drag-to-dismiss, snap). Used for: directory filters, the
   "Contact exporter" inquiry form, and the quote entry CTA.
6. **Touch polish** — 44px targets, `:active` feedback, no tap highlight, no 300ms
   delay (`touch-action`), `overscroll-behavior: contain`, momentum scrolling.
7. **Skeleton loaders** for list/detail screens during navigation.

## PWA

- `manifest.json` — `display: standalone`, forest `theme_color`, `background_color`,
  `start_url: /`, name/short_name, scope.
- **Maskable app icons** generated from the tree-ring brand mark (SVG → 192/512
  PNG + maskable); `apple-touch-icon` for iOS.
- iOS meta: `apple-mobile-web-app-capable`, status-bar style, viewport with
  `viewport-fit=cover`.
- **Service worker** (`public/sw.js`) — precache the app shell + built assets;
  network-first for navigations with an **offline fallback** page; cache-first for
  static assets. Registered from the layout.
- **Install prompt** — capture `beforeinstallprompt`, show an unobtrusive
  "Add to Home Screen" affordance.

## Design system (ui-design-system)

Reuse existing tokens; add app-shell components at touch sizing on the 8pt grid,
WCAG-AA contrast: tab bar, app bar, **list rows with chevrons**, cards,
**segmented controls**, bottom sheet, skeletons, FAB-style primary action where
apt. Adaptive: bottom tabs (mobile) ↔ left rail (desktop).

## Prototype scope (first pass)

Build the shell + PWA + all interactions, applied to the **5 tab screens reusing
existing content** (Home, Exporters list + profile, Species index + detail,
Request-a-Quote; Account = login link to `/admin` or `/dashboard`). Layer the
app shell onto current pages via the new layout + `wire:navigate` rather than
rebuilding pages.

## Honest ceiling

A PWA + these techniques reaches ~95% of native feel; it is not byte-for-byte
native (iOS PWA gesture/notification limits; scroll-physics differences). Get as
close as the web allows; flag anything that cannot match.

## Verification

- Existing Pest suite stays green (pages still render under the new layout).
- New checks: `manifest.json` + `sw.js` reachable; the shell (tab bar) renders on
  public pages; an offline fallback exists.
- Interaction/gesture fidelity is validated manually on a real device.

## Deferred (beyond first prototype)

Per-screen native redesign of content (vs. reusing current layouts), push
notifications, background sync, richer offline data caching, app-store packaging.
