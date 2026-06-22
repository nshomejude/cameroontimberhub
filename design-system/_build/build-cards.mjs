export const meta = {
  name: 'timber-design-system-cards',
  description: 'Author + verify a faithful HTML design-system mirror of the Cameroon Timber Hub UI for claude.ai/design',
  phases: [
    { title: 'Author previews', detail: 'one agent per card: read the real Blade + Heroicon SVGs, emit a self-contained @dsCard preview' },
    { title: 'Verify & fix', detail: 'adversarial check each preview; rewrite if it violates the rules' },
  ],
}

const ROOT = 'C:/laragon/www/cameroontimberhub'
const V = ROOT + '/vendor/blade-ui-kit/blade-heroicons/resources/svg/'
const ic = (...names) => names.map(n => V + n + '.svg')

// real source files
const BRAND   = ROOT + '/resources/views/components/brand-mark.blade.php'
const LAYOUT  = ROOT + '/resources/views/components/layouts/app.blade.php'
const CCARD   = ROOT + '/resources/views/public/partials/company-card.blade.php'
const FILTER  = ROOT + '/resources/views/public/partials/directory-filter-fields.blade.php'
const SHEET   = ROOT + '/resources/views/components/bottom-sheet.blade.php'
const HOME    = ROOT + '/resources/views/home.blade.php'
const DIR     = ROOT + '/resources/views/public/companies/index.blade.php'
const CSHOW   = ROOT + '/resources/views/public/companies/show.blade.php'
const SIDX    = ROOT + '/resources/views/public/species/index.blade.php'
const SSHOW   = ROOT + '/resources/views/public/species/show.blade.php'
const RFQ     = ROOT + '/resources/views/public/rfq/create.blade.php'
const PRICING = ROOT + '/resources/views/public/pricing.blade.php'
const OFFLINE = ROOT + '/public/offline.html'

const INSTRUCTIONS = `You are authoring ONE static HTML preview "card" for a Claude Design design-system project. It mirrors a real Laravel + Tailwind v4 UI (Cameroon Timber Hub — a verified B2B timber directory; palette: forest green, timber/wood accent, sand cream, warm ink text; display font Fraunces, UI font Instrument Sans). Produce ONE self-contained .html file and WRITE it to the given OUTPUT FILE path with the Write tool. Then return strict JSON per the schema.

HARD RULES — follow exactly:
1. The FIRST LINE of the file MUST be exactly the @dsCard marker given in the task (e.g. <!-- @dsCard group="Components" name="Buttons & actions" subtitle="..." -->). Nothing before it.
2. Then a complete HTML document. In <head> include, in order:
   - <meta charset="utf-8"> and <meta name="viewport" content="width=device-width, initial-scale=1">
   - <link rel="preconnect" href="https://fonts.googleapis.com"> / <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   - EXACTLY this font link: <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
   - The shared compiled stylesheet (gives all the design tokens + the exact Tailwind utilities the app uses + the app-shell CSS): <link rel="stylesheet" href="../shared/app.css">
   - A <style> block for STAGE CHROME ONLY (the .ds-stage wrapper / device frame / swatch layout). Also add a tiny :root fallback with the key tokens so foundation swatches survive even if the shared sheet is unavailable: --color-forest-700:#254f35; --color-forest-900:#1b3425; --color-timber-400:#cb9248; --color-sand-50:#fbf9f4; --color-ink:#201e18 (extend as needed).
3. <body class="bg-sand-50 text-ink antialiased"> with a <div class="ds-stage"> wrapper that centers and pads the component(s). Give .ds-stage comfortable padding and a max-width near the task's viewport width.
4. FIDELITY IS EVERYTHING. Reproduce the component using the EXACT Tailwind utility class strings found in the source Blade file(s) you are told to read. Do NOT invent, rename, or guess utility classes — only classes already present in the real source are guaranteed to exist in shared/app.css. For any styling that is NOT in the source (the stage wrapper, a device/phone frame, swatch tiles, left-hand spec labels), use INLINE style="" attributes or the .ds-stage <style> block — never ad-hoc Tailwind.
5. Convert Blade to static HTML completely:
   - Remove every Blade construct: @php/@endphp, {{ ... }}, {!! !!}, @if/@elseif/@else/@endif, @foreach/@endforeach, @class([...]) (resolve to the final flat class list for the state described), @selected/@checked, @csrf, @include, @props, :attr="..." bindings, etc.
   - Replace every <x-heroicon-{m|s|o|c}-NAME ... class="..."> with the RAW inline <svg>...</svg> from the matching vendor file: prefix m->m-NAME.svg, s->s-NAME.svg, o->o-NAME.svg, c->c-NAME.svg. READ each needed file (paths are listed in the task) and inline its exact <svg> markup, but ADD the original element's class="..." onto the <svg> (so h-5 w-5 / text-forest-600 sizing+color still apply) plus aria-hidden="true". Heroicons draw in currentColor, so the text-* color class controls them.
   - Replace <x-brand-mark class="..."> with the brand-mark inline SVG (read brand-mark.blade.php), keeping the class on the <svg>.
   - Replace <x-dynamic-component :component="'heroicon-o-'.X"> with the resolved icon per the task data.
   - Substitute the realistic SAMPLE DATA given in the task for all variables, and resolve all conditionals to the concrete state described.
   - Drop Alpine attributes (x-data, x-show, x-cloak, x-transition, @click, :style, @touch...). For anything that is normally toggled (bottom sheet, install chip), render the RESOLVED/visible state statically.
6. After writing, SELF-CHECK: line 1 is the exact marker; both stylesheet links present; NO leftover {{ }}, @-directives, <x-...> tags, or x-/@ Alpine attributes; every icon is a real inlined <svg>; tags are balanced; phone-frame screens have the bottom tab bar pinned inside the frame.
7. If the task says "phone frame", wrap the screen in an inline-styled device shell: ~390px wide, fixed height per the task, dark rounded bezel (outer radius ~40px, ~10px dark padding), white inner screen with overflow hidden and its own vertical scroll, the top app bar at the top and the bottom tab bar pinned at the bottom INSIDE the frame.

Return JSON: { "path": OUTPUT_FILE, "name": cardName, "group": group, "ok": true, "iconsInlined": [list of icon names you inlined], "notes": "anything notable / any compromise" }.`

// ---- card specs -------------------------------------------------------------
const CARDS = [
  // ===== FOUNDATIONS =====
  { folder:'foundations', slug:'colors', g:'Foundations', name:'Color tokens', subtitle:'Forest · Timber · Sand · Ink ramps', vw:1000, sources:[], icons:[],
    brief:`Color-token reference (no Blade source). Render four labelled ramps as rows of swatches. Each swatch: a rounded square ~84x84 with the color as INLINE background, and beneath it three lines: token name (e.g. "forest-500"), class hint (e.g. "bg-forest-500"), and the hex. Use INLINE styles for the swatches (never depend on Tailwind for the swatch fills). Ramp titles + the "Color tokens" page title in Fraunces (class font-display ok).
HEX VALUES:
Forest (primary brand green): 50 #f1f7f2, 100 #dcebe0, 200 #bad7c2, 300 #8fbb9d, 400 #5d9a71, 500 #3c7d52, 600 #2c6240, 700 #254f35, 800 #20402c, 900 #1b3425, 950 #0e1c14.
Timber (warm wood accent): 50 #faf6ef, 100 #f3e7d2, 200 #e6cca1, 300 #d7ac6c, 400 #cb9248, 500 #bd7a32, 600 #a3612a, 700 #834b27, 800 #6c3f25, 900 #5a3622.
Sand (cream neutrals): 50 #fbf9f4, 100 #f5f0e6, 200 #ebe3d2, 300 #dcceb4, 400 #c4b08a.
Ink (text): ink #201e18, ink-soft #5b554a.
Give light swatches a subtle inset border rgba(0,0,0,.08). Use white label text on dark swatches (forest 600+, timber 600+, ink) and dark text on light swatches. Add a one-line caption under the title.`},

  { folder:'foundations', slug:'typography', g:'Foundations', name:'Typography', subtitle:'Fraunces display + Instrument Sans + eyebrow', vw:900, sources:[], icons:[],
    brief:`Type-scale reference (no Blade source). Base font-size is 118.75% (~19px), applied by shared/app.css. Display = Fraunces (use class font-display); body/UI = Instrument Sans (default). Rows top-to-bottom; each row a small grey spec label (inline-styled, left) and the live sample (right):
- Eyebrow: class "eyebrow" text "Verified timber trade".
- Display XL (h1): class "font-display text-5xl font-semibold text-forest-950" text "Find verified Cameroonian timber".
- Display L (h2): "font-display text-3xl font-semibold text-forest-950" text "Trade timber with confidence".
- Heading (h3): "font-display text-xl font-semibold text-forest-900" text "Bois du Cameroun SARL".
- Body: default paragraph, class "text-ink-soft", "Browse verified exporters by species, region and export market. Documents reviewed by Cameroon Timber Hub."
- Caption: "text-sm text-ink-soft" text "Verification date · 12 Mar 2025".
All those classes exist in shared/app.css. Page title "Typography".`},

  { folder:'foundations', slug:'brand-mark', g:'Foundations', name:'Brand mark', subtitle:'Timber growth-ring logo, sizes & lockup', vw:760, sources:[BRAND], icons:[],
    brief:`Showcase the brand mark — read brand-mark.blade.php and inline its SVG. Render at three sizes (36, 64, 128px) on sand, plus once inside a forest-900 (#1b3425) rounded tile (the SVG carries its own colors, so it reads on dark). Then a lockup: 36px mark next to the wordmark "Cameroon Timber Hub" in class "font-display text-base font-semibold text-forest-900" (as in the nav rail). Page title "Brand mark"; caption "Off-centre timber growth-rings on forest green." Inline styles for sizing/layout.`},

  { folder:'foundations', slug:'surfaces', g:'Foundations', name:'Surfaces & motifs', subtitle:'Radius, elevation, gradient/blur atmosphere', vw:1000, sources:[], icons:[],
    brief:`Surfaces reference. Three sections:
(1) Radius — five white tiles (class "border border-sand-200 bg-white") using rounded-lg, rounded-xl, rounded-2xl, rounded-3xl, rounded-full, each labelled.
(2) Elevation — four white cards (class "rounded-2xl border border-sand-200 bg-white p-6") with shadow-sm, shadow-md, shadow-lg, shadow-xl respectively, labelled.
(3) Signature motif — a ~180px panel reproducing the hero atmosphere: a header div class "bg-gradient-to-b from-forest-50 via-sand-50 to-sand-50" containing a blurred forest orb (a div class "bg-forest-100/50 blur-3xl" with inline width/height ~260x120 and rounded-full) to show the brand background treatment. All these classes appear in the real app so they are in shared/app.css. Page title "Surfaces & motifs".`},

  { folder:'foundations', slug:'iconography', g:'Foundations', name:'Iconography', subtitle:'Heroicons in forest & timber', vw:840,
    sources:[], icons:ic('m-map-pin','s-check-badge','m-magnifying-glass','m-funnel','m-chevron-right','m-chevron-left','m-chevron-up','m-arrow-right','m-arrow-up-right','m-arrow-path','s-shield-check','m-envelope','m-phone','m-check-circle','o-rectangle-stack','o-building-office-2','o-chat-bubble-left-right','o-user-circle','o-home','o-paper-airplane','m-x-mark'),
    brief:`Icon library — read EACH listed svg and inline it into a labelled grid (~6 per row). Each icon at 24px (class "h-6 w-6") inside a tile (white, rounded-xl, border border-sand-200, p-4, centered), color text-forest-700, with the name caption below (map-pin, check-badge, magnifying-glass, funnel, chevron-right, chevron-left, chevron-up, arrow-right, arrow-up-right, arrow-path, shield-check, envelope, phone, check-circle, rectangle-stack, building-office-2, chat-bubble-left-right, user-circle, home, paper-airplane, x-mark). Make 3-4 accent icons text-timber-600 to show the dual palette. Page title "Iconography", caption "Heroicons — outline, solid & mini, in forest & timber."`},

  // ===== COMPONENTS =====
  { folder:'components', slug:'buttons', g:'Components', name:'Buttons & actions', subtitle:'Primary, timber CTA, outline, text link', vw:900, sources:[], icons:ic('m-arrow-right'),
    brief:`Button gallery — reproduce the EXACT button styles used across the app (classes below all exist in shared/app.css). Render each as a <button> or <a> (no href), grouped with small inline-styled captions:
- "Primary": class "rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800" — "Request a quote".
- "Large": "rounded-full bg-forest-700 px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-forest-800" — "Send request".
- "Timber CTA": "inline-flex items-center gap-2 rounded-full bg-timber-400 px-7 py-3.5 text-sm font-semibold text-forest-950 transition hover:bg-timber-300" + inlined m-arrow-right (h-4 w-4) — "Browse exporters".
- "Outline (on dark)": inside a small inline-styled forest-800 (#20402c) tile — "rounded-full border border-forest-600 px-7 py-3.5 text-sm font-semibold text-sand-100 transition hover:bg-forest-700" — "Explore species".
- "Search": "rounded-xl bg-forest-700 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800" — "Search".
- "Text link": "inline-flex items-center gap-1 text-sm font-semibold text-forest-700 transition hover:text-forest-900" + m-arrow-right (h-4 w-4) — "View all".
- "Secondary": "rounded-full border border-sand-300 px-5 py-2.5 text-sm font-medium text-ink-soft transition hover:border-forest-400 hover:text-forest-700" — "Reset filters".
Page title "Buttons & actions". Inline m-arrow-right once and reuse the markup.`},

  { folder:'components', slug:'badges', g:'Components', name:'Badges & pills', subtitle:'Featured, Verified, CITES, status', vw:840, sources:[], icons:ic('s-check-badge'),
    brief:`Badge set — reproduce exactly, each labelled:
- Featured: class "rounded-full bg-timber-100 px-2.5 py-0.5 text-xs font-semibold text-timber-800" — "Featured".
- Most popular: "rounded-full bg-forest-700 px-3 py-0.5 text-xs font-semibold text-white" — "Most popular".
- CITES (light): "rounded-full bg-timber-100 px-2.5 py-0.5 text-xs font-semibold text-timber-800" — "CITES II".
- CITES (dark/ring): on an inline-styled forest-900 (#1b3425) tile — "rounded-full bg-timber-400/20 px-3 py-1 text-xs font-semibold text-timber-200 ring-1 ring-timber-400/40" — "CITES listed — Appendix II".
- Verified profile (inline): "inline-flex items-center gap-1.5 text-sm font-medium text-forest-600" + inlined s-check-badge (h-5 w-5) + "Verified profile".
- Verified pill (dark): on a forest tile — "inline-flex items-center gap-1.5 rounded-full bg-forest-700/70 px-3 py-1 text-sm font-medium text-white" + s-check-badge (h-4 w-4 text-timber-300) + "Verified profile".
- Species chip: "rounded-full bg-sand-100 px-2.5 py-1 text-xs font-medium text-ink-soft" — "Sapele".
- Market tag: "rounded-lg bg-sand-100 px-3 py-1.5 text-sm font-medium text-ink-soft" — "FR".
Page title "Badges & pills".`},

  { folder:'components', slug:'form-fields', g:'Components', name:'Form fields', subtitle:'Inputs, select, textarea, checkbox', vw:760, sources:[FILTER], icons:ic('m-magnifying-glass'),
    brief:`Form controls. The shared field class is "w-full rounded-lg border border-sand-300 bg-white px-3 py-2.5 text-sm text-ink focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none". Inside a white card (class "rounded-2xl border border-sand-200 bg-white p-6", max-width ~520px), stack:
- Search input with leading icon: a "relative block" label with inlined m-magnifying-glass (absolute left, h-5 w-5, color ink-soft) + input[type=search] using the search field classes (with left padding pl-10), placeholder "Search exporters…".
- Select with options All regions / Littoral / Centre / Sud / East.
- Text input placeholder "Your name *".
- Textarea rows=3 placeholder "Describe your requirements *".
- Consent row: "flex items-start gap-2 text-sm text-ink-soft" with a checkbox (class "mt-1 rounded border-sand-300", checked) + "I consent to be contacted by email about this inquiry."
Demonstrate a focus state on the search input by adding the focus ring inline (e.g. style border + box-shadow forest ring). Page title "Form fields".`},

  { folder:'components', slug:'search-bar', g:'Components', name:'Hero search', subtitle:'Home search bar + popular species', vw:760, sources:[HOME], icons:ic('m-magnifying-glass'),
    brief:`The hero search bar from home. Reproduce a form class "flex max-w-xl items-center gap-2 rounded-2xl border border-sand-200 bg-white p-2 shadow-xl shadow-forest-900/5" with: a leading span containing inlined m-magnifying-glass (h-5 w-5, text-ink-soft); input[type=search] class "min-w-0 flex-1 border-0 bg-transparent py-2.5 text-base text-ink placeholder:text-ink-soft/60 focus:outline-none focus:ring-0" placeholder "Search verified exporters…"; submit button class "shrink-0 rounded-xl bg-forest-700 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-forest-800" — "Search". Below, a "Popular species:" label (text-sm text-ink-soft) followed by chips class "rounded-full border border-sand-200 bg-white/70 px-3 py-1 text-sm font-medium text-forest-800 transition hover:border-forest-300 hover:bg-white" for Sapele, Iroko, Ayous, Tali, Padouk, Bubinga. Center on sand.`},

  { folder:'components', slug:'company-card', g:'Components', name:'Company card', subtitle:'Verified exporter directory tile', vw:460, sources:[CCARD], icons:ic('m-map-pin','s-check-badge'),
    brief:`Reproduce the directory company card. Sample: name "Bois du Cameroun SARL" (avatar initial "B"), region "Littoral", city "Douala", is_featured true (Featured badge), species ["Sapele","Iroko","Ayous","Tali"] (chips), verified -> footer "Verified profile" + s-check-badge, verified_at "Mar 2025". Render the <a> card (drop href) centered at ~400px so it looks like one grid cell. Inline m-map-pin and s-check-badge.`},

  { folder:'components', slug:'species-card', g:'Components', name:'Species card', subtitle:'Catalog tile with CITES variant', vw:880, sources:[SIDX], icons:ic('m-arrow-right'),
    brief:`Reproduce the species catalog card (<a> in species/index). Show TWO side by side (~400px each):
(A) "Afrormosia", italic "Pericopsis elata", is_cites_listed true + appendix "II" -> CITES II badge (class "shrink-0 rounded-full bg-timber-100 px-2.5 py-0.5 text-xs font-semibold text-timber-800"), description "A prized West African hardwood with a lustrous golden-brown surface, used in fine furniture and boatbuilding.", footer "View exporters" + m-arrow-right.
(B) "Ayous", italic "Triplochiton scleroxylon", not CITES (no badge), description "A pale, lightweight hardwood widely used for plywood, mouldings and joinery."
Inline m-arrow-right. Sand background.`},

  { folder:'components', slug:'trust-pillar', g:'Components', name:'Trust pillars', subtitle:'3-up value props', vw:1000, sources:[HOME], icons:ic('o-rectangle-stack','o-shield-check','o-paper-airplane'),
    brief:`The trust-pillars 3-up from home. Above the grid, an eyebrow "Why Timber Hub" + h2 "Trade timber with confidence" (font-display text-3xl font-semibold text-forest-950), centered. Each pillar card class "rounded-2xl border border-sand-200 bg-white p-7 shadow-[0_1px_0_rgba(0,0,0,0.02)] transition hover:border-forest-200 hover:shadow-md" with an icon tile class "flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 text-forest-700" (inlined outline icon h-6 w-6), h3 "font-display text-xl font-semibold text-forest-900", paragraph "text-ink-soft":
1) o-rectangle-stack — "Verified directory" — "Browse vetted Cameroonian exporters with reviewed documents and verification badges."
2) o-shield-check — "Compliance first" — "Species CITES status and export documents are reviewed before a profile is published."
3) o-paper-airplane — "Smart RFQ routing" — "Send one request and we route it to the verified suppliers that match."
Grid: 3 columns gap-6 on sand. Inline the three outline icons.`},

  { folder:'components', slug:'stats-row', g:'Components', name:'Stats strip', subtitle:'Hero metric row', vw:720, sources:[HOME], icons:[],
    brief:`The hero stats strip. Reproduce a <dl> class "grid grid-cols-3 gap-6 border-t border-sand-200 pt-8"; each item: dt class "font-display text-4xl font-semibold text-forest-800 sm:text-5xl" + dd class "mt-1 text-sm text-ink-soft". Data: 128 / "Verified exporters", 24 / "Timber species", 17 / "Export markets". Center ~640px on sand.`},

  { folder:'components', slug:'verification-panel', g:'Components', name:'Verification panel', subtitle:'Trust sidebar card', vw:460, sources:[CSHOW], icons:ic('s-shield-check','s-check-badge'),
    brief:`The Verification sidebar panel from company/show. White card class "rounded-2xl border border-sand-200 bg-white p-6 shadow-sm" (~400px): heading class "flex items-center gap-2 font-display text-lg font-semibold text-forest-900" with inlined s-shield-check (h-5 w-5 text-forest-600) + "Verification"; a <ul> of two items, each "flex items-center gap-2 text-sm font-medium text-forest-700" + s-check-badge (h-4 w-4 text-forest-600): "Verified Exporter", "Documents Verified"; a <dl> "mt-4 space-y-2.5 text-sm" with rows (each "flex justify-between", dt text-ink-soft): Status -> "Verified profile" (font-medium text-forest-700); Verification date -> "12 Mar 2025"; Valid until -> "12 Mar 2026"; Reference -> "CTH-2025-0042"; then a disclaimer p class "mt-4 border-t border-sand-100 pt-4 text-xs leading-relaxed text-ink-soft": "Documents reviewed by Cameroon Timber Hub based on information submitted by the company. Buyers should conduct final due diligence before any transaction." Sand background.`},

  { folder:'components', slug:'quote-cta', g:'Components', name:'Quote CTAs', subtitle:'Species aside + closing band', vw:820, sources:[SSHOW,HOME,BRAND], icons:ic('m-arrow-right'),
    brief:`Two CTA surfaces stacked on sand:
(A) Species aside box: class "rounded-2xl bg-forest-800 p-6 text-sand-100" (~360px): h2 "font-display text-lg font-semibold text-white" "Need Sapele?"; p "mt-1 text-sm text-forest-200" "Request a quote and we'll connect you with verified exporters."; pill "inline-flex items-center gap-1.5 rounded-full bg-timber-400 px-5 py-2.5 text-sm font-semibold text-forest-950 transition hover:bg-timber-300" + inlined m-arrow-right (h-4 w-4) + "Request a quote".
(B) Home closing band: a wide panel class "relative overflow-hidden rounded-3xl bg-forest-800 px-8 py-16 text-center text-sand-100"; inline the brand-mark (read brand-mark.blade.php) absolutely in a corner at large size with opacity .1; h2 "font-display text-3xl font-semibold text-white sm:text-4xl" "Looking for verified Cameroonian timber?"; p "text-forest-200" "Browse exporters by species and region, or send a request and we'll route it to matching verified suppliers."; two buttons: timber pill "Browse exporters" + m-arrow-right, and outline "rounded-full border border-forest-600 px-7 py-3.5 text-sm font-semibold text-sand-100" "Explore species".`},

  { folder:'components', slug:'pricing-tier', g:'Components', name:'Pricing tiers', subtitle:'3 plans with most-popular ring', vw:1100, sources:[PRICING], icons:ic('m-check-circle'),
    brief:`The 3-up pricing cards. Each card class "flex flex-col rounded-2xl border bg-white p-7 shadow-sm" + border state ("border-sand-200" normally; the Professional/most-popular card uses "border-forest-300 ring-1 ring-forest-200"). Professional shows a badge class "mb-3 inline-block self-start rounded-full bg-forest-700 px-3 py-0.5 text-xs font-semibold text-white" "Most popular". Each: name "font-display text-2xl font-semibold text-forest-900"; description "mt-2 text-sm text-ink-soft"; price line: number "font-display text-3xl font-semibold text-forest-950" + " XAF / month" (text-sm text-ink-soft); feature <ul> "mt-6 space-y-2.5 text-sm text-ink-soft", each <li> "flex items-center gap-2" + inlined m-check-circle (h-5 w-5 text-forest-500); ends with primary pill "List your company".
Plans:
- Free — "Get listed at no cost." — 0 — features: "Up to 3 gallery images".
- Professional (MOST POPULAR) — "A verified profile that wins buyers." — 50,000 — features: "Up to 8 gallery images", "Verification badge", "Receive RFQ leads".
- Premium — "Maximum reach for serious exporters." — 120,000 — features: "Up to 20 gallery images", "Verification badge", "Receive RFQ leads", "Featured placement", "API access".
Grid: 3 columns gap-6 on sand. Inline m-check-circle once and reuse.`},

  { folder:'components', slug:'empty-state', g:'Components', name:'Empty state & breadcrumb', subtitle:'No-results card + breadcrumb', vw:760, sources:[DIR], icons:ic('o-magnifying-glass','m-chevron-right'),
    brief:`Two pieces on sand (~720px):
(1) Breadcrumb: nav class "flex items-center gap-1.5 text-sm" — "Exporters" (text-forest-700) + inlined m-chevron-right (h-4 w-4 text-ink-soft) + "Bois du Cameroun SARL" (text-ink).
(2) Empty state card class "rounded-2xl border border-dashed border-sand-300 bg-white p-14 text-center": icon tile class "mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-forest-50 text-forest-600" with inlined o-magnifying-glass (h-6 w-6); h2 "font-display text-xl font-semibold text-forest-900" "No verified exporters match these filters yet"; p "text-ink-soft" "Try broadening your search, or tell us what you need and we'll connect you."; two buttons: secondary "rounded-full border border-sand-300 px-5 py-2.5 text-sm font-medium text-ink-soft" "Reset filters" + primary "rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white" "Request a quote".`},

  // ===== APP SHELL =====
  { folder:'app-shell', slug:'top-app-bar', g:'App shell', name:'Top app bar', subtitle:'Brand state + back state', vw:430, sources:[LAYOUT,BRAND], icons:ic('m-chevron-left'),
    brief:`Reproduce the top app bar (the layout's <header class="app-bar sticky top-0 z-30 flex items-center gap-2 border-b border-sand-200 bg-sand-50/90 px-3 backdrop-blur">; the app-bar class in shared/app.css adds min-height 3.5rem + safe-area top padding). Show TWO states, each inside a 390px-wide sand tile:
(A) Root: left = inlined brand mark (h-8 w-8); title h1 class "truncate font-display text-base font-semibold text-forest-900" "Cameroon Timber Hub".
(B) Detail: left = a back button (a round 40px target, class "-ml-1 flex h-10 w-10 items-center justify-center rounded-full text-forest-800") with inlined m-chevron-left (h-6 w-6); title "Bois du Cameroun SARL".
Page title "Top app bar".`},

  { folder:'app-shell', slug:'bottom-tab-bar', g:'App shell', name:'Bottom tab bar', subtitle:'5 tabs, active states', vw:430, sources:[LAYOUT],
    icons:ic('s-home','o-home','s-building-office-2','o-building-office-2','o-rectangle-stack','o-chat-bubble-left-right','o-user-circle'),
    brief:`Reproduce the bottom tab bar (layout <nav class="tab-bar fixed ... grid grid-cols-5 border-t border-sand-200 bg-white/95 backdrop-blur">). Each tab class "flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium" with a 24px icon (h-6 w-6) + label. Active tab: "text-forest-700" with the SOLID icon; inactive: "text-ink-soft" with OUTLINE icon. Tabs in order: Home, Exporters, Species, Quote, Account (icons: home, building-office-2, rectangle-stack, chat-bubble-left-right, user-circle).
Render TWO bars stacked, each at the bottom of a 390px-wide sand tile: bar 1 with HOME active (s-home; others outline), bar 2 with EXPORTERS active (s-building-office-2; others outline). Page title "Bottom tab bar".`},

  { folder:'app-shell', slug:'nav-rail', g:'App shell', name:'Navigation rail', subtitle:'Desktop sidebar', vw:340, sources:[LAYOUT,BRAND],
    icons:ic('s-home','o-building-office-2','o-rectangle-stack','o-chat-bubble-left-right','o-user-circle'),
    brief:`Reproduce the desktop nav rail (layout <aside ... w-60 ...>). A 240px (w-60) white column ~640px tall (border-r border-sand-200): top lockup = inlined brand mark (h-9 w-9) + wordmark "Cameroon Timber Hub" (font-display text-base font-semibold leading-tight text-forest-900); nav list of 5 items, each class "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition". ACTIVE "Home" -> add "bg-forest-50 text-forest-800", solid icon (s-home, h-5 w-5). Inactive -> "text-ink-soft hover:bg-sand-100", outline icons (h-5 w-5): Exporters (o-building-office-2), Species (o-rectangle-stack), Quote (o-chat-bubble-left-right), Account (o-user-circle). Bottom: a "Request a quote" pill class "flex w-full items-center justify-center gap-1.5 rounded-full bg-forest-700 px-4 py-2.5 text-sm font-semibold text-white". Sand page background. Page title "Navigation rail".`},

  { folder:'app-shell', slug:'bottom-sheet', g:'App shell', name:'Bottom sheet', subtitle:'Drag-to-dismiss filter sheet (open)', vw:430, sources:[SHEET,FILTER], icons:ic('m-magnifying-glass','m-funnel'),
    brief:`Reproduce the mobile filter bottom sheet in its OPEN state (drop ALL Alpine x-data/x-show/x-cloak/x-transition/@touch/:style attributes; render statically open). Inside a 390px-wide sand tile (~620px tall): a dim backdrop (a div, inline style position absolute inset 0, background rgba(0,0,0,.4)); the sheet panel pinned bottom: class "bottom-sheet-panel ... max-h-[88vh] overflow-y-auto rounded-t-3xl bg-white shadow-2xl" with a centered drag handle (span class "h-1.5 w-10 rounded-full bg-sand-300"), title h2 "px-5 font-display text-lg font-semibold text-forest-900" "Filter exporters", then the directory filter form (convert directory-filter-fields.blade.php): search input (field class "w-full rounded-lg border border-sand-300 bg-sand-50/60 py-2.5 pl-10 pr-3 text-sm ...") with inlined m-magnifying-glass; three selects (All regions: Littoral/Centre/Sud; All species: Sapele/Iroko/Ayous; All export markets: FR/BE/CN); actions row: "Apply filters" pill (class "inline-flex items-center gap-1.5 rounded-full bg-forest-700 px-5 py-2.5 text-sm font-semibold text-white") + inlined m-funnel (h-4 w-4), and a "Reset" text link (text-ink-soft). Page title "Bottom sheet".`},

  { folder:'app-shell', slug:'pwa-affordances', g:'App shell', name:'Native affordances', subtitle:'Pull-to-refresh + install chip', vw:430, sources:[LAYOUT,BRAND], icons:ic('m-arrow-path','m-x-mark'),
    brief:`Two PWA affordances inside a 390px-wide sand tile:
(A) Pull-to-refresh indicator (layout #pull-indicator): a centered pill class "flex h-9 w-9 items-center justify-center rounded-full bg-white text-forest-600 shadow-md" with inlined m-arrow-path (h-5 w-5), shown ~24px from the top as if pulled. Add a faint caption "Release to refresh" (text-xs text-ink-soft).
(B) Install chip (layout): class "mx-auto max-w-sm rounded-2xl bg-forest-800 p-3 text-sand-100 shadow-xl" with a row "flex items-center gap-3": inlined brand mark (h-9 w-9 shrink-0); p "flex-1 text-sm" "Install Timber Hub for an app-like experience."; Install button class "rounded-full bg-timber-400 px-3 py-1.5 text-xs font-semibold text-forest-950" "Install"; dismiss button (text-forest-300) with inlined m-x-mark (h-5 w-5). Stack A above B. Page title "Native affordances".`},

  { folder:'app-shell', slug:'offline', g:'App shell', name:'Offline screen', subtitle:'PWA offline fallback', vw:430, sources:[OFFLINE], icons:[],
    brief:`The PWA offline fallback. READ public/offline.html (a standalone styled page with a tree-ring SVG and the forest palette). Reproduce its appearance inside a 390px-wide sand tile: keep its tree-ring SVG, heading (e.g. "You're offline") and the reconnect message; preserve its own inline styles/colors. Ensure line 1 is the @dsCard marker and BOTH shared stylesheet links are in <head> (in addition to any inline styles offline.html already has). Page renders as the offline screen.`},

  // ===== SCREENS (phone frames) =====
  { folder:'screens', slug:'home', g:'Screens', name:'Home screen', subtitle:'Hero, search, stats, featured', vw:390, vh:840, screen:true,
    sources:[LAYOUT,HOME,CCARD,BRAND],
    icons:ic('m-magnifying-glass','m-arrow-right','o-rectangle-stack','o-shield-check','o-paper-airplane','o-home','s-home','o-building-office-2','o-chat-bubble-left-right','o-user-circle','m-map-pin','s-check-badge'),
    brief:`FLAGSHIP — full HOME screen in a phone frame (390x840). Top app bar ROOT state (brand mark + "Cameroon Timber Hub"). Content (single column, mobile sizes): eyebrow "Verified timber trade"; h1 "font-display ... font-semibold text-forest-950" at a mobile size (text-3xl or text-4xl) "Find verified Cameroonian timber exporters"; subtitle text-ink-soft "Browse exporters by species, region and export market — documents reviewed by Cameroon Timber Hub."; the hero SEARCH BAR (single column, inlined m-magnifying-glass + Search button); popular-species chips (Sapele, Iroko, Ayous, Tali); the 3-col STATS strip (128 / 24 / 17); a section label "Featured verified exporters"; ONE company card (Bois du Cameroun SARL — Littoral, Douala, Featured, species Sapele/Iroko/Ayous/Tali, Verified profile, Mar 2025). Bottom tab bar pinned inside the frame with HOME active (s-home). Make it clean and faithful. Inline every icon.`},

  { folder:'screens', slug:'directory', g:'Screens', name:'Directory screen', subtitle:'Filter trigger + listing', vw:390, vh:840, screen:true,
    sources:[LAYOUT,DIR,CCARD,FILTER],
    icons:ic('m-funnel','m-chevron-up','m-magnifying-glass','o-home','o-building-office-2','s-building-office-2','o-rectangle-stack','o-chat-bubble-left-right','o-user-circle','m-map-pin','s-check-badge'),
    brief:`Full DIRECTORY screen in a phone frame (390x840). App bar with title "Exporters" (brand on left ok). Content: eyebrow "Verified directory"; h1 "Timber exporters in Cameroon" (mobile font-display size); count line "128 verified exporters — filter by species, region and export market."; the mobile filter TRIGGER button class "flex w-full items-center justify-between rounded-xl border border-sand-200 bg-white px-4 py-3 text-sm font-medium text-forest-800 shadow-sm" = (inlined m-funnel h-5 w-5 text-timber-500 + "Filter exporters") ... (inlined m-chevron-up h-5 w-5 text-ink-soft); then a single-column stack of TWO company cards: (1) Bois du Cameroun SARL — Littoral, Douala, Featured, Sapele/Iroko/Ayous/Tali; (2) Equatorial Hardwoods Ltd — Sud, Kribi, species Tali/Padouk/Bubinga, Verified, Jan 2025. Bottom tab bar pinned inside the frame with EXPORTERS active (s-building-office-2). Inline all icons.`},

  { folder:'screens', slug:'company-detail', g:'Screens', name:'Company detail screen', subtitle:'Profile, verification, contact', vw:390, vh:900, screen:true,
    sources:[LAYOUT,CSHOW,BRAND],
    icons:ic('m-chevron-left','m-chevron-right','m-map-pin','s-check-badge','s-shield-check','m-arrow-up-right','m-envelope','m-phone','o-home','o-building-office-2','s-building-office-2','o-rectangle-stack','o-chat-bubble-left-right','o-user-circle'),
    brief:`Full COMPANY DETAIL screen in a phone frame (390x900). App bar DETAIL state (back chevron + title "Bois du Cameroun SARL"). Content: dark cover header (class "relative overflow-hidden border-b border-sand-200 bg-gradient-to-br from-forest-800 to-forest-950 text-sand-100") with breadcrumb "Exporters › Bois du Cameroun SARL" (inlined m-chevron-right), avatar tile "B", h1 name, location line (inlined m-map-pin + "Littoral, Douala") and a "Verified profile" pill (bg-forest-700/70 + s-check-badge text-timber-300). Then single-column sections: About (a 2-sentence paragraph about a Douala-based exporter of certified hardwood); "Species handled" chips (Sapele, Iroko, Ayous, Tali — each a pill "inline-flex items-center gap-1.5 rounded-full border border-sand-200 bg-white px-4 py-2 text-sm font-medium text-forest-800" + inlined m-arrow-up-right h-3.5 w-3.5 text-timber-500); "Export markets" tags (FR, BE, NL, CN — class "rounded-lg bg-sand-100 px-3 py-1.5 text-sm font-medium text-ink-soft"); the VERIFICATION panel (s-shield-check heading; badges Verified Exporter & Documents Verified with s-check-badge; status/dates/reference dl; disclaimer); and a compact "Contact this exporter" card with name/email inputs, a message textarea, consent checkbox, and a full-width "Send message" pill. Bottom tab bar pinned with EXPORTERS active (s-building-office-2). Inline all icons.`},

  { folder:'screens', slug:'species-detail', g:'Screens', name:'Species detail screen', subtitle:'Properties + exporters', vw:390, vh:880, screen:true,
    sources:[LAYOUT,SSHOW,CCARD],
    icons:ic('m-chevron-left','m-chevron-right','m-arrow-right','m-map-pin','s-check-badge','o-home','o-building-office-2','o-rectangle-stack','s-rectangle-stack','o-chat-bubble-left-right','o-user-circle'),
    brief:`Full SPECIES DETAIL screen in a phone frame (390x880). App bar DETAIL state (back + title "Sapele"). Content: dark gradient header (from-forest-800 to-forest-950 text-sand-100) with breadcrumb "Species › Sapele" (inlined m-chevron-right), h1 "Sapele", italic scientific name "Entandrophragma cylindricum" (text-forest-200). Then About section (a 2-sentence paragraph); a "Properties" grid (2 columns) of cards class "rounded-xl border border-sand-200 bg-white px-4 py-3", each dt (text-xs font-semibold uppercase tracking-wide text-ink-soft) + dd (text-ink): Density "640 kg/m³", Durability "Durable", Janka hardness "1,510 lbf", Typical uses "Furniture & joinery"; the forest-800 "Need Sapele?" quote box (timber pill "Request a quote" + m-arrow-right); then "Verified exporters handling Sapele" with ONE company card (Bois du Cameroun SARL). Bottom tab bar pinned with SPECIES active (s-rectangle-stack). Inline all icons.`},

  { folder:'screens', slug:'quote-form', g:'Screens', name:'Quote form screen', subtitle:'No-account RFQ', vw:390, vh:900, screen:true,
    sources:[LAYOUT,RFQ],
    icons:ic('m-chevron-left','o-home','o-building-office-2','o-rectangle-stack','o-chat-bubble-left-right','s-chat-bubble-left-right','o-user-circle'),
    brief:`Full REQUEST-A-QUOTE screen in a phone frame (390x900). App bar DETAIL state (back + title "Request a quote"). Content: eyebrow "No account needed"; h1 "Request a quote"; intro paragraph "Describe what you need; we'll route it to verified exporters."; then a SINGLE-COLUMN RFQ form using the real field class "w-full rounded-lg border border-sand-300 bg-white px-3 py-2.5 text-ink focus:border-forest-500 focus:ring-2 focus:ring-forest-100 focus:outline-none": fieldset legend "What you need" (font-display text-xl font-semibold text-forest-900) with Species select (Sapele/Iroko/Ayous), Product form select (logs/sawn/veneer), a 2-col row Quantity number + Unit select (m3/ton/pcs), Grade text input; fieldset legend "Your details" with Your name, Email, Company, Destination country (2-letter), Incoterm select (EXW/FOB/CIF); a notes textarea (placeholder "Describe your requirements *"); a consent checkbox row ("I consent to Cameroon Timber Hub sharing this request with verified exporters and contacting me by email."); and the submit pill class "rounded-full bg-forest-700 px-7 py-3.5 text-sm font-semibold text-white" "Send request". Bottom tab bar pinned with QUOTE active (s-chat-bubble-left-right). Inline icons.`},

  { folder:'screens', slug:'pricing', g:'Screens', name:'Pricing screen', subtitle:'Stacked plan cards', vw:390, vh:980, screen:true,
    sources:[LAYOUT,PRICING],
    icons:ic('m-check-circle','m-chevron-left','o-home','o-building-office-2','o-rectangle-stack','o-chat-bubble-left-right','o-user-circle'),
    brief:`Full PRICING screen in a phone frame (390x980). App bar DETAIL state (back + title "Pricing"). Content: centered eyebrow "Plans"; h1 "Choose how you grow"; intro "List for free, or upgrade to a verified profile with buyer leads."; then the three plan cards STACKED single column (Free 0; Professional 50,000 with "Most popular" badge + border-forest-300 ring-1 ring-forest-200; Premium 120,000) — each name, description, price + " XAF / month", feature <ul> with inlined m-check-circle (h-5 w-5 text-forest-500), and "List your company" pill. Features: Free = ["Up to 3 gallery images"]; Professional = ["Up to 8 gallery images","Verification badge","Receive RFQ leads"]; Premium = ["Up to 20 gallery images","Verification badge","Receive RFQ leads","Featured placement","API access"]. End with a small footnote (text-sm text-ink-soft) about due diligence. Bottom tab bar pinned — none of the five maps to Pricing, so render all tabs inactive (text-ink-soft, outline icons). Inline m-check-circle.`},
]

const AUTHOR_SCHEMA = {
  type:'object', additionalProperties:false,
  properties:{
    path:{type:'string'}, name:{type:'string'}, group:{type:'string'},
    ok:{type:'boolean'}, iconsInlined:{type:'array', items:{type:'string'}}, notes:{type:'string'},
  },
  required:['path','name','group','ok'],
}
const VERIFY_SCHEMA = {
  type:'object', additionalProperties:false,
  properties:{
    path:{type:'string'}, ok:{type:'boolean'}, fixed:{type:'boolean'},
    problems:{type:'array', items:{type:'string'}}, name:{type:'string'},
  },
  required:['path','ok','fixed'],
}

const outOf = c => ROOT + '/design-system/' + c.folder + '/' + c.slug + '.html'

function authorPrompt(c){
  const out = outOf(c)
  return INSTRUCTIONS + '\n\n=== THIS CARD ===\n' +
    'OUTPUT FILE (write exactly here): ' + out + '\n' +
    'FIRST LINE MARKER (verbatim): <!-- @dsCard group="'+c.g+'" name="'+c.name+'" subtitle="'+c.subtitle+'" -->\n' +
    'STAGE: viewport width ~'+c.vw+'px' + (c.vh? (', height ~'+c.vh+'px — PHONE FRAME (see rule 7)') : '') + '.\n' +
    'SOURCE BLADE TO READ + CONVERT: ' + (c.sources.length? c.sources.join('  ;  ') : '(none — build from the spec below)') + '\n' +
    'HEROICON SVG FILES TO READ + INLINE: ' + (c.icons.length? c.icons.join('  ;  ') : '(none)') + '\n' +
    'COMPONENT SPEC & SAMPLE DATA:\n' + c.brief
}
function verifyPrompt(c){
  const out = outOf(c)
  return INSTRUCTIONS + '\n\n=== VERIFY / FIX ONE CARD ===\n' +
    'A preview was already authored at: ' + out + '\n' +
    'READ that file and check it against EVERY hard rule above and the spec below. If ANY violation exists (leftover {{ }} / @-directive / <x-...> tag / Alpine x-|@ attribute; wrong or missing line-1 @dsCard marker; missing font or shared/app.css link; an icon that is not a real inlined <svg>; unbalanced tags; a Tailwind class not present in the source; a broken/absent phone frame or bottom tab bar for a screen), FIX it by REWRITING the whole file at the same path with the Write tool, preserving fidelity. If it is already clean, do not rewrite.\n' +
    'LINE 1 MUST BE: <!-- @dsCard group="'+c.g+'" name="'+c.name+'" subtitle="'+c.subtitle+'" -->\n' +
    'SOURCE BLADE: ' + (c.sources.length? c.sources.join(' ; ') : '(none)') + '\n' +
    'ICONS: ' + (c.icons.length? c.icons.join(' ; ') : '(none)') + '\n' +
    'SPEC:\n' + c.brief + '\n\nReturn {path, name, ok, fixed, problems}.'
}

log('Authoring + verifying ' + CARDS.length + ' design-system cards (' +
    CARDS.filter(c=>c.folder==='foundations').length + ' foundations, ' +
    CARDS.filter(c=>c.folder==='components').length + ' components, ' +
    CARDS.filter(c=>c.folder==='app-shell').length + ' app-shell, ' +
    CARDS.filter(c=>c.screen).length + ' screens)')

const results = await pipeline(
  CARDS,
  (c, _c, i) => agent(authorPrompt(c), {
    label: 'author:' + c.slug,
    phase: 'Author previews',
    schema: AUTHOR_SCHEMA,
    effort: c.screen ? 'high' : 'medium',
  }),
  (authored, c, i) => agent(verifyPrompt(c), {
    label: 'verify:' + c.slug,
    phase: 'Verify & fix',
    schema: VERIFY_SCHEMA,
    effort: c.screen ? 'high' : 'medium',
  }).then(v => ({ card: c.slug, group: c.g, name: c.name, path: outOf(c), authored, verify: v }))
)

const done = results.filter(Boolean)
const fixed = done.filter(r => r.verify && r.verify.fixed).length
const clean = done.filter(r => r.verify && r.verify.ok).length
log('Done: ' + done.length + '/' + CARDS.length + ' cards; ' + clean + ' pass verification, ' + fixed + ' were auto-fixed')

return {
  total: CARDS.length,
  completed: done.length,
  verifiedOk: clean,
  autoFixed: fixed,
  byGroup: ['Foundations','Components','App shell','Screens'].map(g => ({
    group: g, count: done.filter(r => r.group === g).length,
  })),
  cards: done.map(r => ({ card: r.card, group: r.group, name: r.name, path: r.path,
    ok: r.verify ? r.verify.ok : false, fixed: r.verify ? r.verify.fixed : false,
    problems: r.verify ? (r.verify.problems || []) : ['no verify result'] })),
}
