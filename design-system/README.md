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
├── tokens/                 ← machine-readable token exports: tokens.json / tokens.css / tokens.scss
├── foundations/            ← colors, typography, design-tokens, dark-mode, brand-mark, surfaces, iconography
├── components/             ← buttons, badges, form-fields, search-bar, cards, panels, pricing, states, …
├── app-shell/              ← top-app-bar, bottom-tab-bar, nav-rail, bottom-sheet, pwa-affordances, offline
├── screens/                ← full mobile screens in phone frames (home, directory, detail, quote, pricing)
├── emails/                 ← real rendered transactional emails (branded "timber" mail theme)
├── admin/                  ← schematic Filament admin screens (dashboard, companies, company form, RFQ triage)
├── exporter/               ← schematic Filament exporter panel (dashboard, leads)
└── _build/                 ← generator workflows + regen.ps1 + render/check helpers (not part of the library)
```

## Cards

| Group | Cards |
|-------|-------|
| **Foundations** | Color tokens · Typography · **Design tokens** · **Dark mode** · Brand mark · Surfaces & motifs · Iconography |
| **Components** | Buttons · Badges · Form fields · Hero search · Company card · Species card · Trust pillars · Stats strip · Verification panel · Quote CTAs · Pricing tiers · Empty state · **Interactive states** |
| **App shell** | Top app bar · Bottom tab bar · Navigation rail · Bottom sheet · Native affordances · Offline screen |
| **Screens** | Home · Directory · Company detail · Species detail · Quote form · Pricing |
| **Emails** | RFQ verification · Inquiry verification |
| **Admin (Filament)** | Dashboard · Companies list · Company form · RFQ triage |
| **Exporter (Filament)** | Dashboard · Leads inbox |

**40 cards in total.**

### Two design languages (by design)

This product intentionally runs **two** visual systems, and the library reflects both honestly:

- **Public site** — the bespoke **forest / timber** editorial + native app shell (Foundations, Components, App shell, Screens). This is the design system proper, and every preview links the compiled `shared/app.css`.
- **Admin & exporter panels** — these run on **Filament**, which uses its own UI with an **Amber** primary and the **Inter** typeface. The `admin/` and `exporter/` cards are *schematic approximations* of those Filament screens, built from the real resource definitions (columns, form sections, actions, overview stats). They are self-contained (their own inline styles, no `shared/app.css`) and are deliberately **not** forest/timber — that would misrepresent the real back-office.
- **Emails** are the **real** rendered output of the Laravel markdown mailables, now using a branded **"timber" mail theme** (forest pill button, sand background, Fraunces headings) — published at `resources/views/vendor/mail/html/themes/timber.css` and wired through `config/mail.php`.

### Token exports

`tokens/` holds the design tokens in three consumable formats, generated from the `@theme` block in `resources/css/app.css`:

- `tokens.json` — structured tokens (colors, type scale with px@19, radius, elevation, motion, breakpoints, app-shell specifics)
- `tokens.css` — `:root` custom properties
- `tokens.scss` — SCSS variables + maps + `forest()/timber()/sand()` helpers

The **Design tokens** foundation card is a visual spec sheet of the same values.

### Dark mode

The public site ships a warm, forest-aligned **dark theme** (a real app feature, not just a mirror card):

- Class-based (`.dark` on `<html>`) via Tailwind's `@custom-variant dark`; a pre-paint boot script applies the saved/OS preference with no flash.
- Toggled from the app bar (sun / moon); the choice persists in `localStorage` and the PWA status-bar tint follows. Until the user picks, it follows the OS.
- Every public component carries additive `dark:` variants; on-dark branded panels (forest covers & CTAs) are left as-is. The **Dark mode** card documents the palette + a light/dark comparison.

### Keeping the mirror fresh

`_build/regen.ps1` regenerates the deterministic parts so the mirror can't silently drift from the app:

```
pwsh design-system/_build/regen.ps1          # rebuild assets → copy shared/app.css → re-render emails
pwsh design-system/_build/regen.ps1 -Check   # exit 1 if shared/app.css is stale (for a pre-commit hook)
```

Install the freshness guard with `cp design-system/_build/pre-commit.sample .git/hooks/pre-commit`. The component / screen / admin / dark-mode **cards** are authored by the Claude workflows in `_build/` (`build-cards.mjs`, `build-admin-cards.mjs`, `build-dark-mode.mjs`) — re-run the relevant one if those components change.

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
