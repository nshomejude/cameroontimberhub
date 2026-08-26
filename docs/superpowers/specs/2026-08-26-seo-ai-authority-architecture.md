# Cameroon Timber Hub — SEO + AI Authority Architecture

Date: 2026-08-26
Status: Draft for review — not yet implemented
Supersedes: the in-progress `/insights` blog build (uncommitted in the worktree as of this doc). That work is not discarded — `Article`, the migration, the importer skeleton and the Filament resource become the base of the Knowledge Centre described here, not a separate blog bolted onto the marketplace.

## 0. Objective, stated once, held constant

Cameroon Timber Hub becomes the reference point — for search engines and for AI answer engines — for "Cameroon timber" and the full constellation of species, supplier, price, regulatory and export queries around it. Not a marketplace with a blog attached. Three layers working together on one platform: **Commerce** (buy/sell), **Knowledge** (understand), **Data** (measure and track).

This is a multi-quarter program, not a sprint. This document is the blueprint; it does not itself write dozens of articles or ship code. It is what gets reviewed, then broken into phased implementation plans (each following the normal spec → plan → build cycle already used on this project).

---

## A. Technical SEO architecture

| Concern | Decision |
|---|---|
| **URL structure** | Flat, human-readable, English at the canonical root; French under `/fr/...` mirroring the same paths (see §L). No query-string-only content pages — filters use query strings, but every meaningfully distinct entity gets a real path. |
| **Canonicalization** | Every page emits a self-referencing `<link rel="canonical">` (already implemented site-wide via `layouts/app.blade.php`). Faceted/filtered marketplace and directory views canonicalize to their **unfiltered parent** unless the filter combination is itself a landing page we've deliberately built (see faceted nav below). |
| **Indexation strategy** | Default `index, follow` on every real content page (already the layout default). `noindex` stays reserved for truly transactional/duplicate surfaces: search results (already `noindex`), auth pages (already `noindex`), account pages, cart/RFQ-in-progress states. Paginated pages beyond page 1 stay indexable but canonicalize to page 1 only when content is a strict subset; category-defining pages (e.g. `/wood-species?category=hardwood`) get real canonical URLs instead of being treated as duplicates. |
| **XML sitemaps** | Split into a **sitemap index** referencing per-type sitemaps: `sitemap-pages.xml`, `sitemap-species.xml`, `sitemap-suppliers.xml`, `sitemap-products.xml`, `sitemap-articles.xml`, `sitemap-glossary.xml`, `sitemap-reports.xml`. Keeps each file under the 50k-URL/50MB limits indefinitely and lets Search Console report indexation per content type — critical for diagnosing which pillar is under-indexed. `lastmod` populated from each model's real `updated_at`. |
| **robots.txt** | Allow major AI crawlers (GPTBot, ClaudeBot, anthropic-ai, PerplexityBot, Google-Extended, CCBot). Explicitly disallow `/search`, `/account/*`, `/api/*` (the JSON API is not for crawling), and admin/dashboard panels. |
| **hreflang** | `en` and `fr` alternates on every page that has a genuine translation, `x-default` pointing at `en`. Implemented as reciprocal pairs (each language version links to the other) — a common failure mode is one-directional hreflang, which Google ignores. |
| **Pagination** | `rel=next`/`rel=prev` is deprecated by Google but still respected by some engines and is free to emit; primary signal is canonical + sitemap coverage of paginated pages that carry unique entities (page 2 of 500 suppliers is real content, not a duplicate). |
| **Faceted navigation** | Directory/marketplace filters (species, region, certification, product type) use query strings by default. A **curated subset** of high-intent facet combinations gets promoted to real static paths with their own canonical, title, and intro copy — e.g. `/wood-species/hardwood`, `/suppliers/douala`, `/products/sawn-timber/iroko`. This is the difference between "faceted nav that creates thin duplicate pages" (a classic large-site SEO failure) and "faceted nav that becomes 40 genuinely useful landing pages." The promoted list is curated from §F, not auto-generated from every possible combination. |
| **Duplicate-content prevention** | One canonical entity per real-world thing. A species has exactly one URL; its French version is a distinct URL declared via hreflang, not a duplicate. Any auto-generated combination page (species × region, species × product form) must add genuinely distinct content (real supplier counts, real availability) — never a templated shell with the facet name swapped in, which is what search engines penalize. |
| **Core Web Vitals / mobile performance** | Already on Tailwind v4 + Vite with no heavy JS framework; keep it that way for content pages — server-rendered Blade, no client-side rendering for anything a crawler needs to see. Species/article images: real `width`/`height` attributes (prevents CLS), `loading="lazy"` below the fold, WebP where feasible. Calculators are the one place genuine client-side JS is justified — keep them isolated, lazy-loaded, and never block the page's LCP. |
| **Crawl budget** | With thousands of long-tail pages (species × products × regions × languages), crawl budget becomes real. Sitemap segmentation (above) plus internal linking discipline (below) is the primary lever — pages with no internal inlinks get crawled rarely regardless of sitemap presence. |
| **Internal linking** | A **related-content engine** (§N) surfaces contextual links automatically: species page → its products → its suppliers → relevant guides → relevant glossary terms. Every academy article ends with real, resolved links into commerce (species/products/suppliers/RFQ), not generic "browse more." This is explicitly the single highest-leverage SEO/AEO mechanism available here — a page that both search engines and AI systems can traverse to understand entity relationships. |
| **Breadcrumbs** | Already implemented as a layout prop (`:breadcrumbs`) emitting `BreadcrumbList` JSON-LD; extend to every new content type below, always matching the visible on-page breadcrumb trail (structured data must match rendered content or it's ignored/penalized). |
| **Image SEO** | Descriptive filenames, real `alt` text describing the actual image (species grain, not "image1.jpg"), `ImageObject` schema on hero images where a page has one canonical image (species, supplier logo, article hero). |
| **Entity IDs** | Every schema.org node gets a stable `@id` (already the pattern for `Organization`/`WebSite` in the layout) so the graph in §H is genuinely a graph, not disconnected JSON-LD blobs per page. |
| **Semantic HTML** | One `<h1>` per page matching the visible title, heading hierarchy that mirrors the actual document outline (no skipped levels, no heading-as-styling), `<article>`/`<nav>`/`<dl>` used for what they mean rather than generic `<div>` soup — this is also what makes a page cleanly parseable by an LLM doing retrieval, which is the whole point of §G. |

---

## B. Information architecture — exact taxonomy and URL patterns

```
/                                   Home
/marketplace                        Product listings (existing)
/marketplace/{product-slug}         Product detail (existing)
/suppliers                          Supplier directory (existing, "Suppliers" pillar)
/suppliers/{company-slug}           Supplier profile (existing)
/wood-species                       Species directory (existing — the public "Species
                                     Academy" entry point, same route)
/wood-species/{species-slug}        Species detail — becomes the full knowledge object (§C)
/rfq/*                              RFQ wizard (existing)
/search                             Cross-entity search (existing)

/knowledge                          Knowledge Centre landing ("Educational Resources" hub)
/knowledge/fundamentals             Pillar: Timber Fundamentals
/knowledge/fundamentals/{slug}      e.g. hardwood-vs-softwood, kiln-dried-vs-air-dried
/knowledge/cameroon-101             Pillar: Cameroon Timber 101
/knowledge/cameroon-101/{slug}      e.g. major-timber-producing-regions, douala-vs-kribi-port
/knowledge/products                 Pillar: Products Academy
/knowledge/products/{slug}          One page per traded form (logs, sawn timber, veneer, ...)
/knowledge/processing               Pillar: Processing Academy
/knowledge/processing/{slug}
/knowledge/grading                  Pillar: Quality & Grading Academy
/knowledge/grading/{slug}
/knowledge/buying                   Pillar: Buyer Academy
/knowledge/buying/{slug}            e.g. fob-vs-cif-vs-cfr-vs-exw, how-to-verify-a-supplier
/knowledge/export                   Pillar: Export Academy
/knowledge/export/{slug}            e.g. exporting-timber-from-cameroon, phytosanitary-requirements
/knowledge/compliance               Pillar: Compliance Academy
/knowledge/compliance/{slug}        e.g. eudr-explained, sigif-ii-explained, flegt-explained
/knowledge/sustainability           Pillar: Sustainability Academy
/knowledge/sustainability/{slug}
/knowledge/logistics                Pillar: Logistics Academy
/knowledge/logistics/{slug}
/knowledge/business                 Pillar: Business Academy
/knowledge/business/{slug}
/knowledge/courses                  Structured multi-module courses
/knowledge/courses/{course-slug}
/knowledge/courses/{course-slug}/{module-slug}
/knowledge/glossary                 Glossary index, A–Z
/knowledge/glossary/{term-slug}     One page per term
/knowledge/case-studies             Case studies index
/knowledge/case-studies/{slug}

/tools                              Calculators landing
/tools/cbm-calculator
/tools/board-foot-calculator
/tools/log-volume-calculator
/tools/container-capacity-calculator
/tools/timber-weight-calculator
/tools/moisture-content-calculator
/tools/incoterm-cost-calculator     (FOB/CIF/CFR comparison, not a customs-duty engine)

/market                             Data Centre landing
/market/price-index                 Cameroon Timber Price Index (§I)
/market/price-index/{species-slug}  Per-species price history/detail
/market/reports                     Reports & Publications index
/market/reports/{report-slug}       Monthly/annual reports
/market/export-destinations         Destination-market index (§K)
/market/export-destinations/{country-slug}   e.g. cameroon-timber-to-china

/insights                           News / short-form updates (regulatory changes, market
                                     moves) — distinct from evergreen /knowledge content
/insights/{slug}

/about, /contact, /verification, /list-your-company    (existing CMS pages, unchanged)
```

Decisions this taxonomy makes deliberately, versus a literal reading of the brief:

- **`/wood-species` not `/timber` as the species root** — the existing `species.index`/`species.show` routes, their FTS indexes, Filament resource and 52-species dataset already live here. Renaming the URL to `/timber` would break every inbound link and sitemap entry built so far for zero SEO gain (the existing slug is already keyword-strong). `/timber` is instead reserved as a possible future **umbrella redirect/hub**, not a rebuild target.
- **`/knowledge` not `/blog` or bare `/education`** — matches the "academy, not blog" framing directly and reads as an authority signal in the URL itself.
- **`/market` not `/timber-prices` + `/market-data` as two roots** — a single Data Centre root with sub-paths is more coherent for both crawl structure and the internal Dataset/Article schema graph (§H) than two sibling top-levels that describe the same pillar.
- **`/suppliers`, not separate `/exporters` and `/sawmills` roots** — the existing `Company` model already has `supplier_type` (Manufacturer/Exporter/Trader/ServiceProvider/LogisticsProvider) and the directory already facets on it. `/suppliers?type=exporter` is the real distinction; a parallel `/exporters` URL tree would be a duplicate-content generator, not a new pillar. If exporter-specific content genuinely diverges (different buyer intent, different on-page copy), that becomes a **promoted facet page** per §A, not a new top-level root.
- **Buyers pillar** is *audience*, not a content type — realized as the Buyer Academy under `/knowledge/buying` plus buyer-facing CTAs threaded through species/product/supplier pages, not a separate URL root with nothing to put in it.

---

## C. Species Knowledge System — the data model

This is the highest-leverage single build in this whole document: species pages are where commercial intent (buy Iroko) and informational/AI intent (what is Iroko, is it legal to export) meet on the same URL, and it's where this platform already has real data (52 seeded species with Cameroon commercial classification).

Extend the existing `Species` model (already has `common_name`, `scientific_name`, `local_names`, `trade_names`, `family`, `description`, `characteristics`, `commercial_category`, `is_promoted`, `log_export_status`, `density_kg_m3_min/max`, `durability_class`, `janka_hardness`, `typical_uses`, `region_availability`, `is_cites_listed`, `cites_appendix`) rather than replacing it. Additions needed for full coverage:

| New field | Purpose |
|---|---|
| `french_name` | Distinct from `local_names` (which covers Cameroonian vernacular names) — the standard French trade name, e.g. "Iroko" vs. "Doussié" for Afzelia. |
| `taxonomy` (jsonb: family, genus, species, order) | Structured, not just prose — lets a `DefinedTerm`/scientific classification render as real structured data, not a paragraph an LLM has to parse. |
| `workability` (text) | Machining/tooling behaviour — currently folded into `characteristics`; promote to a first-class field since it's a distinct buyer question. |
| `drying_behaviour` (text) | Kiln-drying characteristics, shrinkage, checking risk. |
| `treatments` (jsonb) | Common preservative/finishing treatments applied. |
| `grades_available` (jsonb) | Which grading systems/grades this species is commonly sold under (links to Grading Academy). |
| `eudr_risk_note` (text, nullable) | **Same accuracy discipline as `log_export_status`**: only populated with genuinely sourced, dated regulatory information, never inferred. Defaults null. A null value renders as "not yet assessed — consult current EUDR guidance" rather than a blank or a fabricated "compliant." |
| `authoritative_sources` (jsonb: url, publisher, title, accessed_date) | The citation list backing the page — this is the single field that makes the page defensible under §M and useful under §G. |

**What a species page renders**, all backed by real fields, nothing invented:
identification (common/scientific/French/local names, taxonomy) → physical & mechanical properties (density, durability, hardness, workability) → drying & treatment → applications & typical uses → grades available → **legal/export status** (with the existing honest-uncertainty framing already established for `log_export_status`) → CITES status where real → certification context → **live commercial data**: real count of active products in this species, real count of verified suppliers handling it, real "request a quote" CTA → sources cited.

This single page satisfies the Species Academy, the Species Knowledge System, and doubles as the commercial entry point the marketplace needs — one URL, one entity, serving both intents. No parallel "species article" content type — the species page *is* the article, extended.

---

## D. Supplier/Company Entity System

`Company` already carries most of this (`legal_name`/`trade_name`, `region`/`city`, `status`, `verified_at`, `supplier_type`, `response_rate_percent`, `years_experience`, `species()`, `products()`, `exportMarkets()`, `verificationBadges()`, `documents()`). Gaps:

| Gap | Fix |
|---|---|
| **Ports** | New `ports` field or relation — which shipping ports (Douala, Kribi) a supplier typically loads from. Real data, supplier-entered, admin-verified. |
| **MOQ at company level** | Currently MOQ lives per-product (`Product.moq_quantity`); add a company-level "typical MOQ range" summary field for the profile header, derived or entered, not fabricated. |
| **"Last verified date"** | `verified_at` already exists — surface it explicitly on the public profile, since this is a trust signal AI answer engines weight recency/verification language highly for. |
| **Documents surfaced publicly** | `CompanyDocument` exists but is buyer-authenticated/private by design (correct — legal/registration documents aren't public). What *should* surface publicly is the **verification badge and its issuing basis** (already exists via `VerificationBadge`), not the underlying private document. Keep that boundary; do not make private compliance documents public in pursuit of SEO. |
| **LocalBusiness schema** | Add `LocalBusiness` (or `Organization` with `address`) JSON-LD to verified supplier profiles — confirm current coverage and extend per §H. |

---

## E. Content cluster strategy — the hub list, with real page counts

Each hub below is a **pillar page + a defined set of supporting pages**, not an open-ended blog category. Counts are what's needed for genuine topical coverage, not padding.

| Hub (pillar page) | URL | Supporting pages (approx.) |
|---|---|---|
| Cameroon Timber Guide | `/knowledge/cameroon-101` | 12–15 (regions, forest types, species overview, production chain, ports, institutions) |
| Cameroon Timber Species Database | `/wood-species` | 52 species pages (exists) + species-comparison pages for the top 10 confusion pairs (e.g. Sapelli vs. Sipo) |
| Cameroon Timber Supplier Directory | `/suppliers` | Directory (exists) + promoted facet pages (by region, by type — ~10) |
| Cameroon Timber Export Guide | `/knowledge/export` | 15–18 (documentation, customs, phytosanitary, both major ports, containerization) |
| Cameroon Timber Regulations | `/knowledge/compliance` | 12–15 (FLEGT, SIGIF II, EUDR, chain of custody, due diligence, each as its own dated page) |
| Cameroon Timber Traceability | `/knowledge/compliance` (sub-cluster) | 6–8, cross-linked with Trust & Traceability pillar |
| Cameroon Timber Price Index | `/market/price-index` | Data product, not articles — see §I |
| Cameroon Timber Market Reports | `/market/reports` | Recurring (monthly + annual) — see §I |
| Cameroon Timber Buyer Guide | `/knowledge/buying` | 15–20 (the full Buying Academy list) |
| Timber Fundamentals | `/knowledge/fundamentals` | 12–14 |
| Products Academy | `/knowledge/products` | 12–13 (one per traded form) |
| Processing Academy | `/knowledge/processing` | 15–18 |
| Grading Academy | `/knowledge/grading` | 15–17, illustrated |
| Sustainability Academy | `/knowledge/sustainability` | 10–11 |
| Logistics Academy | `/knowledge/logistics` | 12–13 |
| Business Academy | `/knowledge/business` | 12–14 |
| Glossary | `/knowledge/glossary` | 150–250 terms at maturity, starting with ~80 core terms |

**This is the honest scope correction on the original "20 articles" request**: 20 articles cannot cover 17 hubs with real depth. Twenty articles is roughly *one hub's worth* of supporting pages, or a thin single page in each hub. The recommended sequencing (§O) starts with the hubs that carry the most existing commercial-intent overlap — Compliance, Buyer Guide, Export Guide, Species comparisons — and builds outward, rather than spreading a small number of articles a page deep across everything.

---

## F. Search intent database

Rather than reproduce a 200-row keyword table inline (low information density, hard to maintain in prose), this ships as a **structured, versioned CSV/seed** at `content/keywords/search-intents.csv` with columns: `intent_en`, `intent_fr`, `category` (commercial/transactional/informational/regulatory/supplier/species/price/export/buyer-market/compliance/logistics), `target_url_pattern`, `priority` (P0–P3), `notes`. This becomes the single source both content planning and internal-linking automation read from, and it's the artifact the next implementation phase populates with real research (search volume signal, competitor gap analysis) rather than guessed terms. Seeding it with placeholder/guessed volumes would itself violate the "no fabricated data" standard in §M — so this ships as a structure + the terms already named across your briefs, expanded by a dedicated research pass before the full 200 are finalized.

---

## G. AI Search / Answer Engine Optimization — the AI Authority Layer

What actually gets a page retrieved and cited by ChatGPT, Gemini, Claude, Perplexity, Copilot, and Google AI Overviews — in order of leverage, based on how retrieval-augmented systems actually work:

1. **Crawlable and parseable, first.** robots.txt allows the named crawlers. Content is server-rendered HTML, not JS-gated (already the pattern site-wide). Semantic HTML (§A) so an extraction pass gets clean entities, not div soup.
2. **One unambiguous entity per URL**, consistently named, with a stable `@id` — an AI system citing "Cameroon Timber Hub on Iroko export status" needs exactly one canonical page to point to, not three overlapping ones.
3. **Structured data that matches visible content exactly.** This is the largest single failure mode of "AI SEO" as commonly practiced — schema asserting facts the page doesn't visibly state. Every JSON-LD field here must trace to rendered text.
4. **Cited sources, visibly, on every factual/regulatory page** — the `authoritative_sources` field (§C) rendered as a real, visible reference list, not just metadata. Answer engines weight source transparency heavily, and it's also what keeps this content defensible against the "AI-generated filler" problem.
5. **Freshness signals that are real.** `updated_at` surfaced visibly ("Last reviewed: [date]") and *actually* updated when regulations change — especially on the Compliance/Export clusters, which need aggressive maintenance. A stale "last updated" date is worse than none.
6. **Original data** (§I) is the strongest AI-citation asset available, because it's the one thing competitor sites and general web content cannot already have said — an AI system answering "what's the current price for Sapelli from Cameroon" has nowhere else to draw from if this platform is the only source publishing a dated, sourced figure.
7. **Consistent entity identity across the web** — the same Organization `@id`, the same NAP (name/address/phone) everywhere this platform is mentioned externally (directories, associations — §J), so knowledge-graph systems can confidently merge mentions into one entity rather than treating them as unrelated.
8. **`llms.txt`** at the root — a plain-text index of the site's key content for LLM consumption, generated from real published routes (not hand-maintained, so it can't drift stale). Low-certainty as a ranking signal today, high-certainty as a zero-cost hedge given several major crawlers already check for it.

Explicitly **not** doing: hidden text, keyword stuffing, fake citation counts, invented author bios/credentials, or any "prompt injection for AI crawlers" pattern — all of these either don't work against modern retrieval systems or actively damage trust once detected.

---

## H. Structured data / knowledge graph

Schema.org types in use or to add, and the entity graph they encode:

```
Organization (Cameroon Timber Hub, @id stable)
   └─ publishes → WebSite → potentialAction: SearchAction
   └─ owns → ItemList (species, suppliers, articles — per index page)

Company (LocalBusiness/Organization)
   ├─ member of → Organization (Cameroon Timber Hub, as platform operator — NOT claiming
   │              the supplier IS Cameroon Timber Hub; a distinct entity, correctly related)
   ├─ offers → Product ↔ Offer (price, currency, availability — only when real price exists)
   ├─ handles → DefinedTerm (Species, via a custom relation encoded as `additionalProperty`
   │            or `knowsAbout` pointing at the species' DefinedTerm node)
   └─ hasCredential → verification badge (already exists — extend to schema)

Product
   ├─ isRelatedTo → DefinedTerm (Species)
   ├─ offers → Offer
   └─ manufacturer/seller → Company

Species → DefinedTerm (or a Wood-specific extension via `additionalType`)
   ├─ inDefinedTermSet → "Cameroon Timber Species" (the glossary/species set)
   └─ referenced by → Article, Product, Company (via knowsAbout / mentions)

Article / BlogPosting (Knowledge Centre pages)
   ├─ about → DefinedTerm(s) (species, glossary terms it covers)
   ├─ citation → CreativeWork/WebPage (the authoritative_sources list, §C/§G)
   └─ mainEntityOfPage → self

Dataset (Price Index, Export Dashboard — §I)
   ├─ creator → Organization
   ├─ distribution → DataDownload (where a real export exists)
   └─ temporalCoverage, dateModified (real, not decorative)

FAQPage — only on pages whose visible FAQ block backs it (existing rule, carried forward)
BreadcrumbList — every page (existing pattern, extended to new content types)
Place — Douala, Kribi (ports), Cameroon regions — real geographic entities referenced by
        Company.region/city and by Export Academy content, so "timber from Douala" resolves
        to a real Place node rather than a string.
Person — only for real, named, consenting authors/reviewers (§M) — never fabricated.
```

---

## I. Original Data Strategy

The single highest-authority asset this document proposes, and the one requiring the most operational discipline to do honestly.

| Product | What it actually is | Collection method | Validation/citation discipline |
|---|---|---|---|
| **Cameroon Timber Price Index** | Per-species, per-form indicative price ranges (not individual supplier quotes — aggregated) | Sourced from the platform's own real transaction/quote data (`Quote`/`Order` models already exist) once volume is sufficient; until then, explicitly labelled as "indicative, supplier-submitted" ranges, never presented as a market-clearing price without that caveat | Every published figure carries a `dateCollected`, sample size where aggregated, and methodology note. No figure ships without a real number behind it — an empty index is better than a fabricated one. |
| **Supplier Index** | Ranking/scoring of verified suppliers by real signals already captured: response rate, years active, verification status, order-completion history | Derived entirely from existing `Company` fields — zero new collection needed, just a new presentation layer | Score formula published openly (methodology transparency, §M) so it can't be dismissed as a black-box vanity metric. |
| **Export Dashboard** | Aggregate counts: active suppliers by region, products by species, RFQ volume trends | Derived from existing platform data (`Company`, `Product`, `Rfq` tables) | Real-time or daily-refreshed, timestamped. |
| **Species Database** | Already built (§C) | — | — |
| **Monthly Market Report / Annual Industry Report** | Narrative + data synthesis of the above, published as a dated `Article`/`Dataset` pair | Editorial process (§M) reviewing the platform's own aggregate data plus cited external sources (§J) | Each report is immutable once published (versioned, not silently edited) — `Article` gets a lightweight revision-log field so "last updated" claims are auditable. |

**The discipline that makes this defensible**: nothing here is published as a market fact unless it's either (a) derived from this platform's own real operational data with the sample size disclosed, or (b) cited to a named external source with a link and access date. This is the same standard already applied to `log_export_status` and CITES fields on `Species` — extend that pattern platform-wide rather than relaxing it for the sake of having more content.

---

## J. Backlink / authority strategy

Ethical, citation-earning targets, prioritized by realistic reachability:

1. **Trade/industry directories already indexing Cameroon timber companies** — the correct relationship to these is *not* scraping their content, but **ensuring Cameroon Timber Hub itself gets listed there** as a resource, and that verified supplier profiles on this platform link out to (and are referenced by) their own listings on those directories where accurate. Two-way legitimate association, not content extraction.
2. **Government/regulatory bodies** (MINFOF, the FLEGT VPA program) — earn citation by publishing the most accurate, current, well-sourced compliance content (§E Compliance cluster) that these bodies' own stakeholders would find useful to reference.
3. **Research/academic** — species and forestry data pages structured and sourced well enough to be citable in research contexts (real taxonomy, real characteristics, real sources).
4. **International timber trade press and associations** — outreach once the Species Database and Price Index have enough real substance to be worth their citing, not before. Premature outreach to an empty resource wastes the relationship.
5. **Do not**: buy links, exchange links with unrelated sites, or submit to link farms. None of that survives current search-engine link-quality evaluation and actively risks the domain.

---

## K. International market SEO

`/market/export-destinations/{country-slug}` — one page per priority destination (China, India, UAE, Turkey, France, Belgium, Germany, Netherlands, Italy, Spain, expandable). Each page is **not** a templated shell with the country name swapped in (the duplicate-content trap flagged in §A) — it covers what's genuinely different per market: which species that market typically demands, real shipping lead times from Douala/Kribi to that market's major ports, that market's own import/compliance requirements (EUDR for EU destinations specifically, different documentation for others), and real suppliers whose `exportMarkets()` include that country. The `Company.exportMarkets()` relation already captures this — these pages are largely a real-data view over existing relations plus genuinely written market-specific context, not new fabricated content per country.

---

## L. French SEO — real parallel IA, not translation

`/fr/...` mirrors the full English tree with the same slugs translated (`/fr/essences-de-bois`, `/fr/annuaire-fournisseurs`, etc.), each page hreflang-paired with its English counterpart. Real parallel IA means:

- French search intent is **researched separately**, not derived by translating the English list — French-market buyer language differs (e.g. "bois exotique" vs. literal "hardwood," "essence" as the French trade term for species). The `search-intents.csv` (§F) carries genuine `intent_fr` entries, not machine-translated placeholders.
- Species pages already have a `french_name` field (§C) and existing `local_names` jsonb — the French species page is a first-class rendering of the same `Species` record, not a stub.
- Regulatory content (EUDR, FLEGT) is **especially** high-value in French given the EU/Francophone-Africa trade corridor — prioritize this cluster's French version early (§O).
- Content parity is tracked explicitly (a `locale` completeness field per Article/Species) so French isn't a permanent "coming soon" — half-built bilingual IA is worse for SEO than a smaller fully-bilingual set.

---

## M. Content quality standard — the editorial rule set

Every page under `/knowledge`, `/market`, and every `Species`/`Company` factual field is held to:

1. **Factual and source-backed.** Regulatory/legal claims cite a real, named, dated source (§C `authoritative_sources`). No claim about EUDR/FLEGT/SIGIF II compliance status is asserted without a source or is explicitly marked uncertain — same discipline as the existing `log_export_status = Unknown` default.
2. **Current, with a real "last reviewed" date** that changes only when the content is actually reviewed — not bumped cosmetically.
3. **No fabricated attribution.** Author defaults to "Cameroon Timber Hub Editorial Team" (organizational, not a fake person) unless a real, consenting, named reviewer is attached.
4. **No keyword stuffing, no AI-filler padding.** A page's length is whatever the topic genuinely needs — a glossary term might be 150 words; an EUDR explainer might be 2,000. Padding either to hit a word count is explicitly against this standard.
5. **Methodology disclosed wherever data is presented** (§I) — a chart or figure without a stated method and date doesn't publish.
6. **Every regulatory page carries a visible disclaimer** that it is informational, not legal advice — consistent with the caution already applied to the species classification fields.

---

## N. Technical implementation requirements (for the build phase)

- **Entity model**: `Species` (extend, §C), `Company` (extend, §D), `Article` (already scaffolded — repurpose as the Knowledge Centre content type with a `pillar` taxonomy replacing a flat blog-category enum), new `GlossaryTerm`, new `Course` + `CourseModule`, new `Calculator` (config-driven, not a DB-heavy model — these are mostly client-side logic with a thin Blade wrapper), new `MarketReport`/`PriceIndexEntry` for §I, new `KeywordIntent` seed table backing §F.
- **CMS/data architecture**: Filament resources for every new content type, following the existing `ArticleResource` pattern and the shared Filament theme already built. Admins edit everything through `/admin` — no separate CMS.
- **Canonical URLs, schema generation, sitemap generation**: centralize per-model URL/schema/sitemap logic in a small set of shared traits/services (mirroring how `SearchService`/`ProductCatalogueService` already centralize query logic) so every content type gets sitemap + schema "for free" by implementing one interface, rather than each Filament resource hand-rolling its own.
- **Content versioning / last-modified**: `updated_at` already exists everywhere; add a lightweight `content_reviewed_at` distinct from `updated_at` (a typo fix shouldn't reset "last reviewed" for compliance purposes).
- **Entity linking / related-content engine**: a service that, given a Species/Article/Company, returns real related records (not random) — species→products→suppliers is pure Eloquent relations already; article→species/glossary needs `about`/`related_species_ids` jsonb fields populated at write time (editorial responsibility) and rendered at read time (engineering responsibility).
- **Internal linking engine**: markdown/rich-text body content should support a lightweight `[[species:iroko]]`/`[[glossary:cbm]]` internal reference syntax resolved at render time to real links — prevents linkrot when slugs change and makes cross-linking an editorial one-liner instead of hand-written HTML per article.
- **Search**: existing Postgres FTS + trigram infrastructure (already proven on Species/Company/Product) extends to Article/GlossaryTerm with the same pattern — no new search technology needed.
- **Faceted filtering**: existing `SearchService`/`ProductCatalogueService`/`SpeciesDirectoryService` pattern extends to any new filtered index (Glossary A–Z, Reports by year) rather than inventing a new filtering mechanism per content type.
- **API considerations**: the existing `/api/v1` buyer API is unaffected by any of this — Knowledge Centre content is public/server-rendered, not behind the API. If the mobile app later wants article content, it's additive read-only endpoints, not a redesign.

---

## O. 6–12 month execution roadmap

**First 30 days (P0):**
- This document reviewed and approved.
- Technical foundation: sitemap segmentation, `llms.txt`, hreflang scaffolding, robots.txt confirmed (audit first, build only the gap — some of this may already exist).
- `Article` model extended into the Knowledge Centre content type (pillar taxonomy, `related_species_ids`, `authoritative_sources`).
- `GlossaryTerm` model + `/knowledge/glossary` shipped with the first ~80 core terms.
- Species pages extended with the new §C fields (schema only; content backfilled progressively — do not block launch on all 52 species being fully enriched).
- One full hub built end-to-end as the template: **Compliance Academy** (highest commercial + regulatory urgency) — 12–15 pages, real sources, EN + FR.

**60 days (P1):**
- Buyer Academy + Export Academy hubs (15–20 pages combined) — the highest-commercial-intent clusters after Compliance.
- First 2 calculators shipped (CBM, Container Capacity — the two most commonly needed).
- Supplier profile enrichment (§D gaps: ports, MOQ summary, verification date surfaced).
- `search-intents.csv` populated with a real research pass (not placeholder) — first ~100 rows.

**90 days (P2):**
- Products Academy + Processing Academy + Grading Academy hubs.
- Price Index v1 (§I) — even a small real dataset beats none; ships with honest sample-size disclosure.
- French parallel IA begins with the already-shipped Compliance + Buyer/Export hubs (highest ROI for bilingual investment).
- Backlink/authority outreach (§J) begins once the Species Database + Compliance hub give reviewers something substantive to cite.

**6 months:**
- Remaining hubs (Fundamentals, Sustainability, Logistics, Business) shipped.
- Remaining calculators shipped.
- Course/module structure built on top of the by-then-mature hub content (courses are curated learning paths through existing pages, not new content from scratch).
- Full French parity across all shipped English content.
- `search-intents.csv` matures toward the 200+ target with real research backing.

**12 months:**
- Monthly Market Reports have a real 6+ month publication history (a genuine freshness/authority signal that can't be faked or accelerated).
- Annual Industry Report v1.
- Full facet-page promotion (§A) rolled out based on 6 months of real search-query data from Search Console, not guessed upfront.
- Authority strategy (§J) reassessed against actual referring-domain growth.

---

## P. Success metrics

| Category | KPIs |
|---|---|
| Search | Indexed-page count per sitemap segment, ranking position for named seed terms + top 50 from the matured intent database, branded search volume growth |
| Traffic | Organic sessions by pillar (Knowledge vs. Marketplace vs. Directory), entry-page distribution (are people landing on species/compliance pages, i.e. is the funnel working) |
| Authority | Referring domains (count and quality), citations from the §J target list specifically (trackable, not vanity) |
| AI visibility | Manual periodic spot-checks: ask ChatGPT/Gemini/Claude/Perplexity/Copilot the seed questions ("is Iroko legal to export from Cameroon," "who are verified Cameroon timber suppliers") and log whether/how this platform is cited — no reliable automated tool for this exists yet, so this is a manual quarterly audit, reported honestly as such |
| Commercial conversion | RFQs originating from a Knowledge Centre entry page (attributable via the internal-linking CTAs in §A/§N), supplier-profile views from species pages, buyer registrations attributed to organic/Knowledge Centre traffic |
| Data product usage | Price Index page views, report downloads, calculator usage — proxies for whether the "original data" strategy (§I) is actually being used, not just published |
| Content health | % of Compliance-cluster pages reviewed within the last 6 months (freshness discipline, §M), % of species with full §C field coverage, EN/FR parity % |

---

## Audit: what changes on the existing platform to get here

- **Keep unchanged**: `Species`/`Company`/`Product` core schema and their existing directories, the marketplace, RFQ/Quote/Order pipeline, the buyer API, the Filament theme, the messaging/chat-commerce system — none of this is touched by this document.
- **Extend, don't replace**: the in-progress `Article` model/migration/Filament resource/importer become the Knowledge Centre's content engine, retargeted from a flat "blog category" enum to the pillar taxonomy in §B/§E.
- **Rename in framing, not necessarily in URL**: `/insights` stays as the News/short-form-updates surface (regulatory alerts, market moves) — genuinely distinct from evergreen `/knowledge` content, both are useful, they are not the same thing and should not be merged.
- **New, ground-up**: `/knowledge` hub structure, `GlossaryTerm`, calculators, `/market` Data Centre, French `/fr` tree, the `search-intents.csv` research artifact, the sitemap segmentation, `llms.txt`.
- **Fix**: verify `robots.txt` and `llms.txt` are genuinely in the state described in §A/§G before the 30-day plan starts building — audit, not assumption.

---

## What this document is not

It is not a bulk-content order to be filled by parallel agents scraping competitor sites. That approach was explicitly rejected earlier in this project for plagiarism-risk reasons, and nothing in this architecture changes that — original synthesis from cited facts remains the standard, per §M. This document is the structure that makes a small number of well-placed pages in the Compliance/Buyer/Export hubs worth more than many scattered thin pages, and that gives the next 6–12 months of content work a coherent target instead of an open-ended blog backlog.
