export const meta = {
  name: 'timber-admin-cards',
  description: 'Author + verify schematic Filament-amber admin/exporter panel cards for the design system',
  phases: [
    { title: 'Author admin', detail: 'one agent per Filament screen: real resource content, amber/Inter look, inlined heroicons' },
    { title: 'Verify admin', detail: 'adversarial check each card; rewrite if it violates the rules' },
  ],
}

const ROOT = 'C:/laragon/www/cameroontimberhub'
const V = ROOT + '/vendor/blade-ui-kit/blade-heroicons/resources/svg/'
const ic = (...names) => names.map(n => V + n + '.svg')

const LOOK = `You are authoring ONE static HTML preview card that approximates a FILAMENT v4 admin screen for "Cameroon Timber Hub". CRITICAL CONTEXT: the admin/exporter panels are NOT the public forest/timber website — Filament renders its own UI with an AMBER primary color and the Inter typeface. Build a faithful Filament-style back-office approximation, clearly distinct from the public site. Produce ONE self-contained .html file with its OWN inline <style> (do NOT link ../shared/app.css), WRITE it to the OUTPUT FILE path, and return JSON per the schema.

HARD RULES:
1. Line 1 of the file MUST be exactly the @dsCard marker given in the task.
2. <head> contains: <meta charset="utf-8">, <meta name="viewport" content="width=device-width, initial-scale=1">, the Inter font (<link rel="preconnect" ...> then <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">), and ONE inline <style> implementing the Filament look below. No external CSS besides the font.
3. FILAMENT LOOK — approximate with these exact values:
   - Font Inter. Content background #f9fafb (gray-50). Text #111827 (gray-900) primary, #6b7280 (gray-500) muted, #374151 secondary.
   - AMBER primary: amber-600 #d97706 (buttons + active accents), amber-500 #f59e0b, amber-50 #fffbeb, amber-700 #b45309, amber-100 #fef3c7.
   - SIDEBAR: fixed left, width 256px, background #ffffff, right border 1px #e5e7eb, height 100vh. Top: a brand block (a small amber rounded square logo with the tree-ring mark or "CTH" + the brand name in font-weight 600). Then grouped nav: optional small uppercase gray-400 group labels; each nav item a flex row (inlined 20px icon + label, padding 8px 12px, border-radius 8px, gray-700; hover background #f3f4f6). The ACTIVE item: background #fffbeb (amber-50), text #b45309 (amber-700), icon amber-600, font-weight 600.
   - TOPBAR: left margin 256px, sticky top, height 64px, white, border-bottom 1px #e5e7eb, padding 0 24px, flex; left a global-search input placeholder "Search" (rounded-lg, #f9fafb, border #e5e7eb, muted, with a small magnifying-glass icon); right a bell icon + a 36px round avatar (background #fef3c7, amber-700 initials).
   - CONTENT: left margin 256px, padding 28px 32px, background #f9fafb, min-height 100vh.
   - PAGE HEADER: a row with the page title (font-size 24px, font-weight 700, gray-900) and, where relevant, a primary button on the right: background #d97706, color #fff, border-radius 8px, padding 9px 16px, font-size 14px, font-weight 600, with a leading inlined "+" (plus) icon.
   - PANELS/CARDS: white, border-radius 12px, border 1px #e5e7eb, box-shadow 0 1px 3px rgb(0 0 0 / .08).
   - TABLES: inside a white card. A toolbar row on top (a search input + filter "pills": small rounded-full bordered chips like "Status", "Featured", each with a chevron-down). Header row: labels uppercase, font-size 11px, letter-spacing .04em, color #6b7280. Body rows separated by 1px #f3f4f6; cell font-size 14px, padding 12px 16px; first column often a bold name + a gray-500 description line under it. STATUS as rounded-full pills (padding 2px 10px, font-size 12px, font-weight 600): success green bg #dcfce7 text #166534; warning amber bg #fef3c7 text #92400e; danger red bg #fee2e2 text #991b1b; info blue bg #dbeafe text #1e40af; gray bg #f3f4f6 text #374151. Boolean columns: a green check (heroicon check-circle, #16a34a) or an em dash. Row actions on the right: small bordered buttons (e.g. "Edit") and/or a ⋮ ellipsis-vertical icon button (the "Manage"/"Triage" menu trigger). A pagination footer line: "Showing 1–N of M".
   - STAT WIDGETS: a responsive row of white stat cards; each: a small gray-500 label, a big number (font-size 30px, font-weight 700, gray-900), a description line (gray-500, 13px), and a color accent (a 3px colored top border OR a small colored sparkline/Δ) per the spec color (success green / warning amber / neutral gray).
   - FORM SECTIONS: each a white card with a section title (font-weight 600) + a helper description (gray-500, 13px), then fields in a CSS grid per the spec column count. Field = label (13px, gray-700, margin-bottom 4px) above an input (border 1px #d1d5db, border-radius 8px, padding 8px 12px, font-size 14px, white). Collapsed sections show a chevron-down and a one-line muted summary instead of fields.
4. Inline EVERY icon as the real Heroicon <svg> read from the listed vendor files. Put width:20px;height:20px (nav/actions) or 16px (inline) via inline style; color via currentColor. aria-hidden="true". Read each file you need.
5. Substitute the SAMPLE DATA from the task. NO Blade, NO {{ }}, NO <x-...> tags, NO Alpine x-/@ attributes — fully static HTML.
6. The screen must be unmistakably a Filament back-office admin (amber + Inter + sidebar + the named table/form/stats) — visually distinct from the public forest/timber site.
7. Self-check before returning: marker on line 1; Inter linked; amber primary present; every icon a real inlined <svg>; tags balanced; the screen clearly matches its name.

Return JSON: { "path": OUTPUT_FILE, "name": cardName, "group": group, "ok": true, "iconsInlined": [...], "notes": "" }.`

const CARDS = [
  { folder:'admin', slug:'dashboard', g:'Admin (Filament)', name:'Admin dashboard', subtitle:'Platform overview stats', vw:1280,
    icons:ic('o-home','o-building-office-2','o-rectangle-stack','o-document-text','o-shield-check','o-inbox','o-credit-card','o-bell','o-magnifying-glass','o-check-circle','o-clock','o-arrow-trending-up'),
    brief:`ADMIN panel dashboard. Sidebar brand "Cameroon Timber Hub — Admin". Nav (group "Manage"): Dashboard (o-home, ACTIVE), Companies (o-building-office-2), Species (o-rectangle-stack), Company documents (o-document-text), Verification requests (o-shield-check), RFQs (o-inbox), Plans (o-credit-card). Topbar with search + bell + avatar "AN".
Page title "Dashboard". Then the PlatformOverview StatsOverviewWidget — a row of 4 stat cards (exact content):
1) label "Verified companies", number 128, description "Live in the directory", accent SUCCESS (green).
2) label "Pending companies", number 9, description "Awaiting review", accent WARNING (amber).
3) label "Open verifications", number 5, description "In the verification queue", accent NEUTRAL (gray).
4) label "RFQs to triage", number 12, description "New verified requests", accent WARNING (amber).
Below the stats, an AccountWidget-style card: "Signed in as Amina Njoya" / "Administrator" with a small "Sign out" link, and a muted note "Filament v4 · Amber theme".`},

  { folder:'admin', slug:'companies-table', g:'Admin (Filament)', name:'Companies — list', subtitle:'Directory moderation table', vw:1280,
    icons:ic('o-building-office-2','o-home','o-rectangle-stack','o-document-text','o-shield-check','o-inbox','o-credit-card','o-bell','o-magnifying-glass','o-plus','o-funnel','o-chevron-down','o-pencil-square','m-ellipsis-vertical','o-check-circle','o-star','s-check-badge'),
    brief:`ADMIN Companies list (resource: Companies). Sidebar with Companies ACTIVE. Page header: title "Companies" + primary amber button "New company" (with + icon). Toolbar: a search input + filter pills "Status", "Featured", "Trashed" (each with chevron-down).
Table columns (exact): Company (bold legal_name + gray description = trade_name), Region, Status (badge), Badge (boolean check), Species (count badge — a gray rounded-full number), Featured (boolean). Rows:
1) "Bois du Cameroun SARL" / "Bois du Cameroun" · Littoral · Status Verified (success green) · Badge ✓(green check) · Species 12 · Featured ✓.
2) "Equatorial Hardwoods Ltd" / "Equatorial Hardwoods" · Sud · Verified (green) · Badge ✓ · Species 8 · Featured —.
3) "Sangha Timber Co." / "Sangha Timber" · East · Pending (warning amber) · Badge — · Species 5 · Featured —.
4) "Korup Forest Exports" / "Korup Exports" · South-West · Suspended (danger red) · Badge — · Species 3 · Featured —.
Row actions (right): a bordered "Edit" button + a ⋮ (m-ellipsis-vertical) "Manage" menu trigger. Under the table a pagination footer "Showing 1–4 of 41". The Species count is a small gray rounded-full badge; Status is a colored pill; Badge/Featured booleans are a green check-circle or an em dash.`},

  { folder:'admin', slug:'company-form', g:'Admin (Filament)', name:'Company — edit form', subtitle:'Sectioned resource form', vw:1100,
    icons:ic('o-building-office-2','o-home','o-rectangle-stack','o-document-text','o-shield-check','o-inbox','o-credit-card','o-bell','o-magnifying-glass','o-chevron-down','o-chevron-right','o-photo'),
    brief:`ADMIN Company edit form (resource: Companies form schema). Sidebar Companies ACTIVE. Page header: title "Edit company" + primary amber "Save changes" button. Render the form as stacked white section cards (exact sections + fields, pre-filled):
SECTION "Identity" (2 columns): Legal name* = "Bois du Cameroun SARL"; Trade name = "Bois du Cameroun" (helper "Public display name (falls back to legal name)."); Slug = "bois-du-cameroun-sarl" (helper "Leave blank to auto-generate. Changing a live slug breaks inbound links."); RCCM = "RC/DLA/2014/B/1234"; NIU = "M021400012345P".
SECTION "Profile" (3 columns): Description (a full-width textarea with 2 sentences about a Douala hardwood exporter); Year founded = 2014; Employees = 85; Annual capacity (m³) = 12000.
SECTION "Location & contact" (3 columns): Region = "Littoral"; City = "Douala"; Country code = "CM"; Address (full width) = "Zone Industrielle Bonabéri"; Email = "export@boisducameroun.cm"; Phone = "+237 6 99 00 11 22"; Website = "https://boisducameroun.cm".
SECTION "Branding" (2 columns): Logo (a file-upload dropzone with o-photo icon + "Drop a file or browse"); Cover (same).
SECTION "SIGIF (captured, not integrated)" — COLLAPSED: show a chevron + muted summary "SIGIF operator ID, permit / title numbers".
SECTION "SEO" — COLLAPSED: chevron + muted summary "Meta title, meta description".
Inputs use the Filament field styling. Required fields show a subtle asterisk.`},

  { folder:'admin', slug:'rfq-triage', g:'Admin (Filament)', name:'RFQs — triage', subtitle:'Verified request queue + actions', vw:1280,
    icons:ic('o-inbox','o-home','o-building-office-2','o-rectangle-stack','o-document-text','o-shield-check','o-credit-card','o-bell','o-magnifying-glass','o-funnel','o-chevron-down','m-ellipsis-vertical','o-play-circle','o-check-circle','o-paper-airplane','o-x-circle','o-no-symbol','o-archive-box'),
    brief:`ADMIN RFQs triage table (resource: Rfqs). Sidebar RFQs ACTIVE. Page header title "RFQs". Toolbar: search + filter pills "Status" and a "Flagged / spam" toggle pill.
Columns (exact): Reference, Buyer (bold buyer_name + gray description = country code), Requested (items summary text, wrapped), Status (badge), Spam (a small badge whose color follows the score: >=70 red, >=30 amber, else gray), Received. Rows:
1) "RFQ-2025-0042" · Buyer "Müller Holz GmbH" / "DE" · Requested "Sapele · sawn · 2×40ft container; Iroko · logs · 60 m³" · Status New (info blue) · Spam 8 (gray) · 12 Mar 2025.
2) "RFQ-2025-0039" · "Lagos Timber Imports" / "NG" · "Ayous · sawn · 120 m³" · In review (warning amber) · Spam 22 (gray) · 11 Mar 2025.
3) "RFQ-2025-0035" · "Shanghai Hong Mu" / "CN" · "Tali · logs · 3 containers" · Approved (success green) · Spam 5 (gray) · 09 Mar 2025.
4) "RFQ-2025-0031" · "qwerty" / "—" · "asdf asdf lorem" · Spam (danger red) · Spam 88 (red) · 08 Mar 2025.
Row action (right): a ⋮ (m-ellipsis-vertical) "Triage" menu trigger. Optionally render ONE row's open Triage menu as a small dropdown listing the actions with their icons + colors: "Start review" (play-circle, info), "Approve" (check-circle, green), "Route to exporters" (paper-airplane, green), "Reject" (x-circle, red), "Mark spam" (no-symbol, red), "Close" (archive-box, gray). Pagination footer "Showing 1–4 of 18".`},

  { folder:'exporter', slug:'dashboard', g:'Exporter (Filament)', name:'Exporter dashboard', subtitle:'Self-service supplier panel', vw:1280,
    icons:ic('o-home','o-building-office-2','o-document-text','o-inbox','o-bell','o-magnifying-glass','s-check-badge','o-check-circle','o-arrow-right'),
    brief:`EXPORTER panel dashboard (separate Filament panel at /dashboard, brand "Cameroon Timber Hub", amber). Sidebar nav: Dashboard (o-home, ACTIVE), Company (o-building-office-2), Company documents (o-document-text), Leads (o-inbox). Topbar with search + bell + avatar "JM".
Page title "Welcome back". Content: an AccountWidget card "Signed in as Jean Mbarga" / "Bois du Cameroun SARL". A "Verification: Verified" status card (green s-check-badge + "Your profile is live in the directory"). A small stats row of 3 cards: "Active leads" 6 (description "2 new this week", warning amber accent); "Documents" 4 (description "2 verified", neutral); "Plan" "Professional" (description "Verified profile + RFQ leads", success green). A subtle CTA card: "Keep your profile fresh" with a small "Edit company →" link (arrow-right).`},

  { folder:'exporter', slug:'leads', g:'Exporter (Filament)', name:'Leads — inbox', subtitle:'Routed buyer leads', vw:1280,
    icons:ic('o-inbox','o-home','o-building-office-2','o-document-text','o-bell','o-magnifying-glass','o-funnel','o-chevron-down','o-arrow-right','m-ellipsis-vertical'),
    brief:`EXPORTER Leads table (resource: Leads). Sidebar Leads ACTIVE. Page header title "Leads". Toolbar: search + a "Status" filter pill.
Columns (exact): Buyer, Source (gray badge), Status (badge), Country, Activity (date), Received (date). Rows:
1) "Müller Holz GmbH" · Source "rfq" (gray) · Status New (info blue) · DE · 12 Mar 2025 · 12 Mar 2025.
2) "Lagos Timber Imports" · "rfq" (gray) · Contacted (warning amber) · NG · 10 Mar 2025 · 09 Mar 2025.
3) "—" · "directory" (gray) · Quoted (info blue) · CN · 07 Mar 2025 · 05 Mar 2025.
4) "Bordeaux Bois" · "rfq" (gray) · Won (success green) · FR · 03 Mar 2025 · 28 Feb 2025.
Row action (right): a bordered "Open" button (with arrow-right icon). Pagination footer "Showing 1–4 of 9".`},
]

const AUTHOR_SCHEMA = {
  type:'object', additionalProperties:false,
  properties:{ path:{type:'string'}, name:{type:'string'}, group:{type:'string'}, ok:{type:'boolean'}, iconsInlined:{type:'array',items:{type:'string'}}, notes:{type:'string'} },
  required:['path','name','group','ok'],
}
const VERIFY_SCHEMA = {
  type:'object', additionalProperties:false,
  properties:{ path:{type:'string'}, name:{type:'string'}, ok:{type:'boolean'}, fixed:{type:'boolean'}, problems:{type:'array',items:{type:'string'}} },
  required:['path','ok','fixed'],
}

const outOf = c => ROOT + '/design-system/' + c.folder + '/' + c.slug + '.html'

function authorPrompt(c){
  const out = outOf(c)
  return LOOK + '\n\n=== THIS CARD ===\n' +
    'OUTPUT FILE (write exactly here): ' + out + '\n' +
    'FIRST LINE MARKER (verbatim): <!-- @dsCard group="'+c.g+'" name="'+c.name+'" subtitle="'+c.subtitle+'" -->\n' +
    'STAGE: a desktop admin screen ~' + c.vw + 'px wide. Render the full sidebar + topbar + content.\n' +
    'HEROICON SVG FILES TO READ + INLINE (use the ones this screen needs): ' + c.icons.join('  ;  ') + '\n' +
    'SCREEN SPEC & SAMPLE DATA:\n' + c.brief
}
function verifyPrompt(c){
  const out = outOf(c)
  return LOOK + '\n\n=== VERIFY / FIX ONE CARD ===\n' +
    'A card was authored at: ' + out + '\nREAD it and check against EVERY hard rule + the spec below. If ANY violation exists (missing/wrong line-1 marker; missing Inter font; no amber primary; an icon not inlined as real <svg>; leftover Blade/placeholder; unbalanced tags; not recognizably the named Filament screen; uses forest/timber instead of amber), FIX by REWRITING the whole file at the same path. If already clean, do not rewrite.\n' +
    'LINE 1 MUST BE: <!-- @dsCard group="'+c.g+'" name="'+c.name+'" subtitle="'+c.subtitle+'" -->\n' +
    'ICONS: ' + c.icons.join(' ; ') + '\nSPEC:\n' + c.brief + '\n\nReturn {path, name, ok, fixed, problems}.'
}

log('Authoring + verifying ' + CARDS.length + ' Filament admin/exporter cards')

const results = await pipeline(
  CARDS,
  (c) => agent(authorPrompt(c), { label:'author:'+c.slug, phase:'Author admin', schema:AUTHOR_SCHEMA, effort:'high' }),
  (authored, c) => agent(verifyPrompt(c), { label:'verify:'+c.slug, phase:'Verify admin', schema:VERIFY_SCHEMA, effort:'high' })
      .then(v => ({ card:c.slug, group:c.g, name:c.name, path:outOf(c), authored, verify:v }))
)

const done = results.filter(Boolean)
const clean = done.filter(r => r.verify && r.verify.ok).length
const fixed = done.filter(r => r.verify && r.verify.fixed).length
log('Done: ' + done.length + '/' + CARDS.length + '; ' + clean + ' pass, ' + fixed + ' auto-fixed')

return {
  total: CARDS.length, completed: done.length, verifiedOk: clean, autoFixed: fixed,
  cards: done.map(r => ({ card:r.card, group:r.group, name:r.name, path:r.path,
    ok: r.verify ? r.verify.ok : false, fixed: r.verify ? r.verify.fixed : false,
    problems: r.verify ? (r.verify.problems || []) : ['no verify result'] })),
}
