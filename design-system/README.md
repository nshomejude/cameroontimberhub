# Cameroon Timber Hub — Design System

A standalone HTML mirror of the production UI, built for publishing to a **Claude Design** project
(claude.ai/design). Each file is a self-contained `@dsCard` preview; together they form a browsable
component library that stays faithful to the live Laravel + Tailwind v4 app.

## Preview locally

Open **`index.html`** in a browser (or via the dev server: `http://127.0.0.1:8000/../design-system/index.html`
won't resolve — instead open the file directly, or `start design-system/index.html`). It renders every card
in a gallery grouped by section.

Each individual card (e.g. `components/company-card.html`) also opens on its own.

## How the previews stay faithful

- **Styling** — every card links `shared/app.css`, which is the **compiled** Tailwind stylesheet copied from
  `public/build/assets/app-*.css`. So the cards use the exact design tokens (forest / timber / sand / ink),
  the Fraunces + Instrument Sans type scale, and the app-shell CSS that production uses.
- **Markup** — each card reproduces the real Blade component's exact utility classes (no invented classes).
- **Icons** — Heroicons are inlined as the real `<svg>` from `vendor/blade-ui-kit/blade-heroicons`.
- **Fonts** — Fraunces + Instrument Sans load from Google Fonts.
- Chrome that isn't part of the app (stage padding, swatch tiles, phone frames) uses inline styles only.

## Structure

```
design-system/
├── index.html              ← local gallery (open this)
├── shared/app.css          ← compiled Tailwind + tokens + app-shell CSS
├── foundations/            ← colors, typography, brand-mark, surfaces, iconography
├── components/             ← buttons, badges, form-fields, search-bar, cards, panels, pricing, …
├── app-shell/              ← top-app-bar, bottom-tab-bar, nav-rail, bottom-sheet, pwa-affordances, offline
├── screens/                ← full mobile screens in phone frames (home, directory, detail, quote, pricing)
└── _build/                 ← the workflow script that generates the cards (not part of the library)
```

## Cards

| Group | Cards |
|-------|-------|
| **Foundations** | Color tokens · Typography · Brand mark · Surfaces & motifs · Iconography |
| **Components** | Buttons · Badges · Form fields · Hero search · Company card · Species card · Trust pillars · Stats strip · Verification panel · Quote CTAs · Pricing tiers · Empty state |
| **App shell** | Top app bar · Bottom tab bar · Navigation rail · Bottom sheet · Native affordances · Offline screen |
| **Screens** | Home · Directory · Company detail · Species detail · Quote form · Pricing |

## Publishing to Claude Design

1. Authorize once: run **`/design-login`** (works even on an API-key/provider-token session), or `/login`
   with a Claude subscription.
2. The **DesignSync** push uploads `shared/`, `foundations/`, `components/`, `app-shell/`, and `screens/`
   into a design-system project (new or chosen).
3. The Design System pane builds its cards from each preview's **first-line** `<!-- @dsCard group="…" name="…" -->`
   marker — no manual registration needed.

The sync is a one-directional publish of a *representation* of the UI. It never touches the Blade files or
the running app. Re-run after UI changes to keep the library current (rebuild `shared/app.css` from the latest
`public/build` first).
