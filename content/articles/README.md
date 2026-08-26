# `content/articles/` — the article source contract

Every file in this directory is one article on `/insights`. Files are imported
with:

```bash
php artisan articles:import          # normal run
php artisan articles:import --dry-run
php artisan articles:import --force  # overwrite admin edits (see below)
```

The importer is **idempotent**: running it twice with unchanged files writes
nothing the second time.

Files whose name starts with `_`, and this `README.md`, are ignored.

---

## File shape

```
---
<YAML frontmatter>
---

<markdown body>
```

The file **must** begin with `---` on line 1. The body below the closing `---`
is markdown and is stored verbatim.

### Supported YAML

The app does not ship a YAML library and is not adding one, so frontmatter is
parsed by `App\Support\Frontmatter`, which covers exactly this subset:

- `key: value` scalars — bare, `"double"` or `'single'` quoted, `true`/`false`,
  `null`/`~`, integers and floats
- folded (`>`) and literal (`|`) block scalars
- block lists of scalars (`- one`)
- block lists of maps (`- question: …` / `  answer: …`)
- nested maps
- one-level flow collections: `[a, b]` and `{name: x, role: y}`
- `#` comments on their own line

Anchors, aliases, tags, multi-document files and nested flow collections are
**not** supported and raise an error rather than being silently mis-read.
Indentation must be spaces; a tab is an error.

Filename convention: `<slug>.md` (e.g. `eudr-compliance-cameroon-timber.md`).
The filename is the fallback slug when `slug:` is omitted.

---

## Frontmatter fields

| Key | Type | Required | Notes |
|---|---|---|---|
| `title` | string | **yes** | The `<title>` and card headline. |
| `slug` | string | no | Defaults to the filename. Lower-case, hyphenated. **This is the identity key** — changing it creates a second article. |
| `category` | enum | **yes** | One of `guides`, `species`, `regulation`, `export`, `market`, `buying`. An unknown value fails the file. |
| `h1` | string | no | On-page headline when it should differ from `title`. |
| `excerpt` | string | no | 1–2 sentences. Used on cards, in the lead paragraph and as the meta-description fallback. |
| `meta_title` | string | no | ≤ 255 chars. Defaults to `title`. |
| `meta_description` | string | no | ≤ 320 chars. |
| `keywords` | list of strings | no | Also accepts a comma-separated string. |
| `status` | enum | no | `draft` \| `published` \| `archived`. Default `published`. Only `published` is publicly visible. |
| `published_at` | date/datetime | no | Any parseable date, e.g. `2026-08-20` or `2026-08-20 09:00:00`. Required in effect for `published` — the importer falls back to the file's mtime. A **future** date keeps the article invisible until then. |
| `author` | string _or_ map | no | `author: {name: Jane Doe, role: Timber grader}`. **Leave it out unless a real, named person actually wrote it** — the byline then reads "Cameroon Timber Hub editorial" and the JSON-LD attributes to the organisation. Never invent a person or credentials. |
| `author_name` / `author_role` | string | no | Flat alternative to `author`. |
| `hero_image` | string | no | Path on the `public` disk (`articles/foo.jpg`), a root-relative path (`/img/foo.jpg`), or an absolute URL. |
| `reading_minutes` | int | no | Auto-computed from the body at ~220 wpm if omitted. |
| `faqs` | list of `{question, answer}` | no | Rendered on-page **and** emitted as `FAQPage` JSON-LD. Rows missing either half are dropped. |
| `sources` | list of `{url, label}` | no | Rendered as the citations block. Non-http(s) URLs are dropped. |
| `related_species` | list of species slugs | no | Resolved to ids at import; an unmatched slug is warned about and dropped. Renders as the "Species covered here" rail. |
| `related_product_types` | list of `ProductType` values | no | e.g. `sawn_timber`, `logs`, `veneer`. |

Any key not in this table is ignored, with a warning.

---

## Body rules

- The body is **markdown**, not HTML. Raw HTML is escaped on render, so a `<div>`
  in the body will appear as literal text — use markdown.
- **Do not write an `#` H1.** The page renders `h1` / `title` itself; a second
  H1 in the body is an SEO defect.
- Structure the piece with `##` (H2). **The table of contents is built from the
  H2s**, so an article with fewer than two H2s gets no TOC. `###` is available
  for sub-points but does not appear in the TOC.
- Tables, lists, blockquotes, code fences and images are all styled.

### Internal links

Never hard-code a site URL. Use these link schemes; they are resolved to real
routes at render time and survive route changes:

| Written as | Resolves to |
|---|---|
| `[Sapele](species:sapele)` | that species' page |
| `[all species](species:)` | `/species` |
| `[a supplier](suppliers:acme-timber-sarl)` | that company profile |
| `[verified suppliers](suppliers:)` | `/companies` |
| `[sawn timber](marketplace:sawn_timber)` | `/marketplace` filtered by product type |
| `[the marketplace](marketplace:)` | `/marketplace` |
| `[post an RFQ](rfq:)` | the RFQ wizard |
| `[quote Iroko](rfq:iroko)` | the RFQ wizard pre-set to that species |
| `[our EUDR guide](insights:eudr-compliance)` | another article |

External links are written as ordinary markdown links and rendered with
`rel="nofollow noopener"`.

---

## Overwrite rule

The importer will not silently revert somebody's work in the admin.

| Situation | Result |
|---|---|
| Slug not in the database | **created** |
| File's mtime is not newer than the row's last import | **skipped** — unchanged |
| File changed, row untouched in the admin since the last import | **updated** |
| File changed, but the row was saved in the admin since the last import | **skipped**, reported as a conflict |
| `--force` | **updated** regardless — admin edits to the fields the file sets are discarded |

"Has the file changed?" compares the file's mtime with `articles.source_mtime`.
An "admin edit" is `articles.updated_at` moving past `articles.source_synced_at`,
which every import stamps to the import moment.

Practical consequence: **touch the file** (any change, or `touch`) when you want
a re-import to take effect, and expect a conflict report — not a silent
overwrite — if staff have edited that article in the meantime.

---

## Worked example

```markdown
---
title: "EUDR compliance for Cameroon timber: what buyers must collect in 2026"
slug: eudr-compliance-cameroon-timber
category: regulation
excerpt: >
  The EU Deforestation Regulation shifts the burden of proof onto the importer.
  Here is exactly what to demand from a Cameroonian exporter before you book
  freight.
meta_title: "EUDR compliance for Cameroon timber (2026 buyer's guide)"
meta_description: What EUDR requires from importers of Cameroonian timber — geolocation data, legality evidence, due-diligence statements and the documents to demand.
keywords:
  - EUDR
  - Cameroon timber
  - due diligence
status: published
published_at: 2026-08-20
hero_image: articles/eudr-compliance.jpg
related_species:
  - sapele
  - iroko
faqs:
  - question: Does EUDR apply to timber already on the water?
    answer: >
      Placement on the EU market is the trigger, not the shipping date, so a
      consignment that clears after the application date needs a due-diligence
      statement.
sources:
  - url: https://eur-lex.europa.eu/eli/reg/2023/1115/oj
    label: Regulation (EU) 2023/1115 (EUDR)
  - url: https://www.minfof.cm/
    label: MINFOF — Ministry of Forestry and Wildlife
---

## What EUDR actually asks of you

Ordinary paragraph text, with an internal link to [Sapele](species:sapele) and
a route into the funnel when it genuinely helps the reader — [post an
RFQ](rfq:sapele).

## The documents to demand

1. Legality evidence
2. Geolocation of the harvest plots
3. A due-diligence statement
```
