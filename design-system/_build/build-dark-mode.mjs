export const meta = {
  name: 'timber-dark-mode',
  description: 'Additively add Tailwind dark: variants across the public Blade views/components',
  phases: [
    { title: 'Add dark variants', detail: 'one agent per file: insert dark: classes, never removing existing ones' },
    { title: 'Verify dark', detail: 'confirm additive-only + correct coverage; fix if needed' },
  ],
}

const ROOT = 'C:/laragon/www/cameroontimberhub'
const f = p => ROOT + '/' + p

const FILES = [
  'resources/views/home.blade.php',
  'resources/views/public/companies/index.blade.php',
  'resources/views/public/companies/show.blade.php',
  'resources/views/public/partials/company-card.blade.php',
  'resources/views/public/partials/directory-filter-fields.blade.php',
  'resources/views/public/species/index.blade.php',
  'resources/views/public/species/show.blade.php',
  'resources/views/public/rfq/create.blade.php',
  'resources/views/public/rfq/thanks.blade.php',
  'resources/views/public/rfq/verified.blade.php',
  'resources/views/public/inquiry/verified.blade.php',
  'resources/views/public/pricing.blade.php',
  'resources/views/components/bottom-sheet.blade.php',
].map(f)

const MAPPING = `DARK-MODE MAPPING for the Cameroon Timber Hub public UI (warm, forest-aligned dark palette).
This app uses class-based dark mode (a \`.dark\` ancestor + the \`dark:\` variant). Add the dark: counterpart ALONGSIDE each existing light class (keep the light class).

SURFACES:
- bg-white            -> add  dark:bg-[#1f1d18]
- bg-sand-50          -> add  dark:bg-[#14130f]
- bg-sand-50/60       -> add  dark:bg-[#26241e]
- bg-sand-100         -> add  dark:bg-[#26241e]
- bg-forest-50        -> add  dark:bg-[#1b2c22]
GRADIENTS (hero/section backgrounds):
- from-forest-50      -> add  dark:from-forest-950
- via-sand-50         -> add  dark:via-[#14130f]
- to-sand-50          -> add  dark:to-[#14130f]
- (from-forest-800 to-forest-950 and similar already-dark gradients: LEAVE as-is)
BORDERS:
- border-sand-100     -> add  dark:border-[#26241e]
- border-sand-200     -> add  dark:border-[#2c2a24]
- border-sand-300     -> add  dark:border-[#3a352e]
TEXT:
- text-ink            -> add  dark:text-[#f1ece1]
- text-ink-soft       -> add  dark:text-[#b3ab9b]
- text-forest-950 / text-forest-900   -> add  dark:text-sand-100
- text-forest-800     -> add  dark:text-forest-200
- text-forest-700     -> add  dark:text-forest-300
- text-forest-600     -> add  dark:text-forest-400
BADGES / ACCENTS:
- bg-timber-100 (with text-timber-800)  -> add  dark:bg-timber-400/15  and on the text  dark:text-timber-200
HOVER:
- hover:bg-sand-100   -> add  dark:hover:bg-white/5
- hover:bg-white      -> add  dark:hover:bg-[#26241e]
- (hover:border-forest-200/300, hover:shadow-*, hover:-translate-*: LEAVE)
INPUTS: leave focus:* and placeholder:* as-is.

DO NOT TOUCH (these are already on dark branded surfaces or are on-dark text — adding dark: would break them):
- Anything inside a panel whose background is bg-forest-700 / bg-forest-800 / bg-forest-900 / a from-forest-800.. gradient (e.g. the dark cover headers, the forest CTA boxes, the closing band). Their text-white / text-sand-100 / text-forest-200 / text-forest-300 / bg-timber-400 must stay.
- Primary buttons \`bg-forest-700 ... text-white\` (they read fine on dark) — LEAVE.
- text-white, text-sand-100, text-timber-200/300 anywhere — LEAVE.`

const RULES = `You are adding DARK-MODE support to ONE real Blade file. Use the Edit tool to make targeted, ADDITIVE edits.

ABSOLUTE RULES:
1. ADD ONLY. Never remove, rename, or change any existing class, attribute, text, URL, or Blade directive. You may ONLY insert \`dark:...\` tokens into existing class="..." strings and into the string keys of @class([ ... ]) arrays.
2. Apply the mapping ONLY to elements that are LIGHT surfaces / light-context text / light borders. Use judgment: if an element sits inside a dark branded panel (bg-forest-700/800/900 or a from-forest-800.. gradient) or is on-dark text (text-white, text-sand-100, etc.), DO NOT add a dark: variant to it.
3. Preserve Blade exactly (@php, @if, @foreach, @class, {{ }}, <x-...>, components). Do not reformat unrelated lines.
4. Keep edits minimal and correct. It's better to leave an element unchanged than to mis-darken an on-dark element.
5. After editing, the file must still contain ALL of its original classes and markup — your changes are strictly additions.

` + MAPPING

const AUTHOR_SCHEMA = {
  type:'object', additionalProperties:false,
  properties:{ file:{type:'string'}, edits:{type:'number'}, ok:{type:'boolean'}, notes:{type:'string'} },
  required:['file','ok'],
}
const VERIFY_SCHEMA = {
  type:'object', additionalProperties:false,
  properties:{ file:{type:'string'}, ok:{type:'boolean'}, fixed:{type:'boolean'}, additiveOnly:{type:'boolean'}, problems:{type:'array',items:{type:'string'}} },
  required:['file','ok','fixed'],
}

log('Adding dark: variants to ' + FILES.length + ' public Blade files (additive only)')

const results = await pipeline(
  FILES,
  (file) => agent(
    RULES + '\n\nTARGET FILE (read it, then Edit it in place): ' + file +
    '\n\nApply the mapping additively. Return {file, edits, ok, notes}.',
    { label:'dark:'+file.split('/').slice(-2).join('/'), phase:'Add dark variants', schema:AUTHOR_SCHEMA, effort:'high' }
  ),
  (authored, file) => agent(
    RULES + '\n\nVERIFY ONE FILE: ' + file +
    '\nRead it. Confirm: (a) ADDITIVE ONLY — every original light class is still present (nothing removed/renamed/altered); (b) Blade syntax intact; (c) dark: variants were correctly added to the LIGHT surfaces/text/borders and NOT added to on-dark elements inside forest-700/800/900 panels. If anything is wrong (a light class was removed, Blade broken, an on-dark element wrongly darkened, or major light surfaces missing their dark: variant), FIX it with the Edit tool. Return {file, ok, fixed, additiveOnly, problems}.',
    { label:'verify:'+file.split('/').slice(-2).join('/'), phase:'Verify dark', schema:VERIFY_SCHEMA, effort:'high' }
  ).then(v => ({ file, authored, verify:v }))
)

const done = results.filter(Boolean)
const clean = done.filter(r => r.verify && r.verify.ok).length
const fixed = done.filter(r => r.verify && r.verify.fixed).length
const additive = done.filter(r => r.verify && r.verify.additiveOnly !== false).length
log('Done: ' + done.length + '/' + FILES.length + '; ' + clean + ' ok, ' + fixed + ' fixed, ' + additive + ' additive-only')

return {
  total: FILES.length, completed: done.length, verifiedOk: clean, autoFixed: fixed, additiveOnly: additive,
  files: done.map(r => ({ file: r.file.replace(ROOT + '/', ''),
    ok: r.verify ? r.verify.ok : false, fixed: r.verify ? r.verify.fixed : false,
    additiveOnly: r.verify ? r.verify.additiveOnly : null,
    problems: r.verify ? (r.verify.problems || []) : ['no verify'] })),
}
