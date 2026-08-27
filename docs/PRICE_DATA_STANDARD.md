# Price Data Standard — CTH Price (Brief §8.1 / Rule 0.7)

**Source of truth:** `CTH_Claude_Code_Build_Brief.md` rule 0.7 (Section 0) and §8.1 "Price
Intelligence module ('CTH Price')" · **Deliverable 7 of §13** · **Current state:** [`docs/AUDIT.md`](AUDIT.md)
**Date:** 2026-08-27

This is the canonical schema every price-bearing write must reconcile against or emit into.
It does not replace any existing table — every price field already captured in `products`,
`rfqs`/`rfq_items`, `quotes`/`quote_items`, `orders`/`order_items`, `company_species`,
`leads`, `plans` and `quote_counter_offers` stays where it is. `PriceObservation` is a
write-time derivative row, not a new source of truth for commercial data.

**Headline finding:** there is no free-text price field anywhere in the codebase to
migrate. Every price column found (13 across 8 tables) is already `decimal(14,2)` paired
with a `char(3)` currency column under a Postgres `CHECK` constraint. Rule 0.7's "audit any
existing free-text price fields and migrate them" finds **nothing to migrate** — that is
itself the finding, not a gap. What is missing is (a) some basis dimensions on a few tables,
and (b) the `PriceObservation` emission itself, which does not exist at all.

---

## 1. Reused vs new enums

The rule is: **reuse an enum already enforced by a Postgres `CHECK` constraint on a live
table before inventing a second list that can drift from it.**

| Field | Enum | Status | Evidence |
|---|---|---|---|
| Currency | `App\Enums\RfqCurrency` (`XAF,USD,EUR,GBP,CNY`) | **Reused.** Already the values behind the `_currency_check` CHECK on `rfqs`, `quotes`, `orders`, `leads`, `plans`, `company_species`, `receipts`, `quote_counter_offers` — 8 tables, one list. | `app/Enums/RfqCurrency.php:10-16`; e.g. `database/migrations/2026_06_22_120010_create_rfqs_table.php:39` |
| Product form | `App\Enums\TimberForm` (`logs,sawn,veneer,plywood,other`) | **Reused.** Same values as the `_form_check` CHECK on `rfq_items`, `quote_items`, `order_items`, `company_species`. Brief's §10 `PriceObservation.product_form` maps directly onto it. | `app/Enums/TimberForm.php:11-16`; `database/migrations/2026_06_22_120020_create_rfq_items_table.php:23` |
| Quantity unit | `App\Enums\RfqUnit` (`m3,ton,pcs,container`) | **Reused**, with a caveat. This is the unit enum used on the three item tables that carry a `unit_price` (`rfq_items`, `quote_items`, `order_items`). `products`/`company_species` instead use `App\Enums\PriceUnit` (`m3,m2,pcs,ton`), which has `m2` where `RfqUnit` has `container`. `PriceObservation.unit` should accept the **union** of both (`m3,m2,pcs,ton,container`); do not force `products` onto `RfqUnit` or vice versa — that would drop `m2` (needed for veneer/flooring priced by area) or `container` (needed for logistics-linked quotes). | `app/Enums/RfqUnit.php:13-16`, `app/Enums/PriceUnit.php:10-13` |
| Incoterm | `App\Enums\RfqIncoterm` (`EXW,FOB,CFR,CIF,DAP`) | **Reused for the export leg**, but incomplete against its own database — the CHECK constraints on `rfqs`/`quotes`/`orders` also allow `'other'`, which the enum has no case for. See gap G1 below. | `app/Enums/RfqIncoterm.php:7-11` vs `database/migrations/2026_06_22_120010_create_rfqs_table.php:39` |
| Basis (fob/ex_mill/delivered/…) | **New**: `App\Enums\PriceBasis` | **New, justified.** §8.1's basis list ("FOB Douala, ex-mill Yaoundé, delivered Douala") is broader than an incoterm — `ex_mill` and `delivered` are domestic pricing conventions, not Incoterms 2020 terms, and nothing in the schema currently distinguishes "delivered to a named place under DAP" from "delivered, informally, within Cameroon." Proposed cases: `fob`, `cif`, `cfr`, `exw`, `dap`, `ex_mill`, `delivered`, `other` — the first five alias 1:1 onto `RfqIncoterm` for export rows so a `PriceObservation` built from an export order can carry both `incoterm` (unchanged) and this wider `basis` without translation loss. | New file, not yet created |
| Volume band | **New**: `App\Enums\PriceVolumeBand` | **New.** Nothing today buckets a quantity into a band; every quantity column is a raw decimal. §8.1's aggregation cells require a small, fixed number of bands so N≥5/M≥10 thresholds (Section 4) are checked per band, not per exact quantity. Proposed cases, m³-equivalent: `sample` (<1), `small` (1–10), `medium` (10–50), `large` (50–200), `bulk` (200+) — mirrors the tier logic already used for sponsorship tiers (`GAP_PLAN.md` §6.6) so the pattern is consistent across the codebase. | New file, not yet created |
| Grade | **No enum — stays free string.** | Every grade column (`products.grade`, `rfq_items.grade`, `quote_items.grade`, `order_items.grade`, `company_species.grade`) is already an unconstrained `varchar`, and the brief does not specify a fixed grade list. Species-level grading conventions vary too much (Grade A/B/C, FAS, Select, Common…) to force into one enum without a separate audit of `species` content, which is out of scope here. `PriceBand` cells should normalise grade to lower-case/trim at aggregation time, not by adding a CHECK constraint. | `database/migrations/2026_06_25_100010_create_products_table.php:22`, `.../2026_06_22_120020_create_rfq_items_table.php:18` |

**Gap G1 (enum drift already present, independent of this brief item):** `RfqIncoterm` is
missing the `'other'` case that its own governing CHECK constraint permits on `rfqs`,
`quotes` and `orders`. Any row saved with `incoterm = 'other'` cannot round-trip through
the enum today (`RfqIncoterm::from('other')` throws). Fix while touching this file for
`PriceBasis` — add `case Other = 'other';` to `RfqIncoterm`.

---

## 2. `PriceObservation` — field list and types

Reconciled against every existing price-bearing column found. Nullable columns are ones no
current write path can populate for every source type (e.g. `moisture` is meaningless for a
`logistics` cost observation).

| Column | Type | Nullable | Source of truth today |
|---|---|---|---|
| `id` | bigint PK | no | — |
| `source` | `varchar(12)` CHECK `IN ('reference','listed','quoted','transacted','logistics','processing')` | no | §8.1 data layers 1–5 |
| `species_id` | `bigint` FK → `species.id` | yes | `products.species_id`, `rfq_items.species_id`, `quote_items.species_id`, `order_items.species_id`, `company_species.species_id` |
| `species_text` | `varchar(180)` | yes | `rfq_items.species_text` — freeform fallback when no `species_id` match |
| `product_form` | `varchar(20)` — `TimberForm` values | yes | `*_items.form`, `company_species.form` |
| `grade` | `varchar(120)` | yes | `products.grade`, `*_items.grade`, `company_species.grade` |
| `moisture_content` | `varchar(60)` | yes | `products.moisture_content`, `rfq_items.moisture_content` — **not yet on `quote_items`/`order_items`/`company_species`**, see §3 |
| `dimensions` | `varchar(255)` | yes | `rfq_items.dimensions`, `quote_items.dimensions`, `order_items.dimensions` — **not on `products`**, which instead has discrete `thickness_mm`/`width_min_mm`/`width_max_mm`/`length_min_m`/`length_max_m`; the write path that emits from `products` must serialise those into one string |
| `quantity` | `decimal(14,2)` | yes | `*_items.quantity`, `products.moq_quantity` (MOQ, not a transacted quantity — see note in §5), `company_species.min_order_m3` |
| `unit` | `varchar(10)` — union of `RfqUnit`/`PriceUnit`, see §1 | no | as above |
| `currency` | `char(3)` — `RfqCurrency` | no | every price-bearing table already has this column |
| `price` | `decimal(14,2)` | no | `*.price_amount`, `*.unit_price`, `*.total_amount` ÷ quantity where only a line total exists |
| `basis` | `varchar(12)` — new `PriceBasis` | yes | `rfqs.incoterm`, `quotes.incoterm`, `orders.incoterm`, `quote_counter_offers.incoterm` — all nullable today; domestic (`company_species`, `products`) rows have no basis column at all, see G2 |
| `region` | `varchar(120)` | yes | `companies.region` (seller side) or `orders.destination_country_code`/`rfqs.destination_country_code` (buyer side) — no table has a single authoritative "region" for a price row, see G3 |
| `volume_band` | `varchar(10)` — new `PriceVolumeBand` | no | derived at write time from `quantity` + `unit`, never stored elsewhere |
| `observed_at` | `timestamptz` | no | the source row's `created_at` (listed/quoted) or the commercial event's timestamp (`orders.awarded_at`/`payment_recorded_at` for transacted) |
| `org_hash` | `char(64)` | no | one-way hash of `company_id` (+ pepper) — **never** the `company_id` itself; this is what makes anonymisation (§4) enforceable at the storage layer instead of only in a query filter |
| `source_type` | `varchar(30)` | no | polymorphic pointer, e.g. `product`, `quote_item`, `order_item`, `company_species`, `rfq_item`, `reference_price_source` |
| `source_id` | `bigint` | yes (null for `reference`) | the row's own PK in `source_type`'s table |
| `created_at` | `timestamptz` | no | insert time (may differ from `observed_at` for backfilled reference data) |

`ReferencePriceSource`, `PriceBand`, `PriceIndex`, `PriceAlert`, `PriceMethodologyConfig`
follow the shapes given verbatim in the brief's §10 line — no reconciliation was needed
against existing tables because **none of the five exists in any form**, confirmed by:

```
grep -ril "PriceObservation\|ReferencePriceSource\|PriceBand\|PriceIndex\|PriceAlert\|PriceMethodologyConfig" app database
```

returning zero matches. There is no `Price*` model, migration, Filament resource, job, or
event anywhere in the repository.

---

## 3. Which existing tables need a column added — and which don't

Per the brief's own instruction (§0 rule 3): propose additive migrations, never rewrite
silently. "Full basis" = species, product form, grade, moisture, dimensions, quantity, unit,
currency, price, basis/incoterm, region, volume band, date.

| Table | Present today | Missing | Verdict |
|---|---|---|---|
| `products` | species, form (via `product_type`, coarser than `TimberForm`), grade, moisture, dimensions (as 5 discrete columns), quantity (MOQ only), unit, currency, price, `created_at`/`updated_at` | **`basis`**, **`region`** (denormalised from `company.region` at write time) | Add 2 columns |
| `rfqs` + `rfq_items` (header + line, read together — this is how the brief's `RFQ(type, buyer, lines[], …)` model in §10 is already shaped) | species, form, grade, dimensions, quantity, unit, moisture (item); currency, price(target_amount, budget-level not per-line), basis(incoterm), region(destination_country_code) (header) | Per-line **price** is a budget ceiling, not a firm price — acceptable, RFQ rows feed `PriceObservation` at `source = quoted`/`transacted` stage instead, never at `source = reference`-equivalent RFQ-post time. **No column gap.** | Add 0 columns |
| `quotes` + `quote_items` | species, form, grade, dimensions, quantity, unit, price (`unit_price`); currency, basis(incoterm) (header) | **`moisture_content`** on `quote_items`; **`region`** on `quotes` (today only inherited from the parent `rfq`, which is fine at query time but means `PriceObservation` emission must join, not read one row) | Add 1 column (`quote_items.moisture_content`) |
| `orders` + `order_items` | species, form, grade, dimensions, quantity, unit, price; currency, basis(incoterm), region(destination_country_code) (header) | **`moisture_content`** on `order_items` | Add 1 column |
| `company_species` | species, form, grade, price, currency, quantity(`min_order_m3`, MOQ not transacted quantity) | **`moisture_content`**, **`dimensions`**, **`unit`** (implicitly always m³ today — `min_order_m3` name bakes the unit in, blocking veneer priced by m²), **`basis`**, **`region`** (via `company.region`, same pattern as `products`) | Add 5 columns |
| `leads` | price(`value_amount`), currency | Not a source in §8.1's list (`reference\|listed\|quoted\|transacted\|logistics\|processing`) — a lead is a CRM value estimate, often pre-quote. **Out of scope for `PriceObservation`.** | Add 0 columns — explicitly excluded |
| `plans` | price, currency | Subscription/platform pricing, not a trade price. **Out of scope.** | Add 0 columns — explicitly excluded |
| `quote_counter_offers` | quantity, unit, price(`unit_price`), currency, basis(incoterm) | species/form/grade/dimensions/moisture — a counter-offer is a negotiation round *on* a quote and inherits the quoted item's basis by reference; adding those columns here would duplicate `quote_items` and risk the two drifting during a live negotiation | Add 0 columns — inherit via `quote_id` join |

**G2 (basis on domestic pricing):** `products` and `company_species` are the two tables with
commercial price but **no incoterm/basis column at all** — `RfqIncoterm` only exists on the
three RFQ-lineage tables. This is consistent with them being catalogue/domestic records
rather than negotiated export deals, but §8.1 explicitly wants "ex-mill Yaoundé" as a first-class
basis value, and today there is nowhere on `products` to record that a listed price is
ex-mill vs delivered. Hence the `basis` column addition above rather than reusing `incoterm`.

**G3 (no single "region"):** region is currently scattered — seller-side on
`companies.region`, buyer/destination-side on `*.destination_country_code` (a country code,
not the Douala/Yaoundé/Bafoussam-level region §8.1's example prices use). The
`PriceObservation.region` write path should default to **seller region** (`company.region`)
for `listed`/`quoted`/`transacted` sources — this is what determines "ex-mill Yaoundé" vs
"FOB Douala" — and to the reference source's stated region for `reference` rows. This is a
convention to fix in code, not a schema gap on its own.

---

## 4. Aggregation, anonymisation and enforcement rules

Restated from §8.1's prose "Safeguards" as concrete, testable rules — not copied verbatim.

1. **Minimum sellers.** A `PriceBand` row may be published (`status = 'published'`) only if
   `COUNT(DISTINCT org_hash)` of contributing `PriceObservation` rows in its cell is
   `>= PriceMethodologyConfig.min_sellers` (default **5**).
2. **Minimum observations.** The same cell must also have
   `COUNT(*) >= PriceMethodologyConfig.min_observations` (default **10**) counting
   `listed`+`quoted`+`transacted` rows only — `reference` rows contribute context but never
   count toward either threshold, since they are not independent market signals.
3. **Widen-or-withhold, in that fixed order.** If either threshold fails: (a) widen grade →
   species-only, (b) widen region → national, (c) if still short, the band is **not
   computed** and the UI renders "insufficient data" — never a partial or estimated band
   silently substituted for a real one.
4. **Anonymisation by construction.** `PriceObservation.org_hash` is a one-way hash; the
   originating `company_id` is never stored on the observation row itself, only reachable via
   `source_type`/`source_id` → the original commercial row, which is access-controlled
   separately (staff/owner only) and never joined into a public `PriceBand`/`PriceIndex`
   response.
5. **30-day lag on transacted data.** A `PriceObservation` with `source = 'transacted'` is
   excluded from any `PriceBand`/`PriceIndex` computation where
   `observed_at > now() - PriceMethodologyConfig.lag_days` (default **30**). `listed` and
   `quoted` observations are not lagged — they are already forward-looking, not a completed
   deal whose parties could be inferred from timing.
6. **Blind quoting stays enforced upstream.** No `PriceObservation` or derived band may ever
   expose a live, in-progress RFQ's competing quotes to another supplier on that same RFQ —
   this is already the existing `RfqTriageService`/quote-visibility behaviour and this
   standard does not change it; `PriceObservation` rows for `source = 'quoted'` are only
   readable in aggregate, never traceable back to "which supplier quoted what on RFQ #X" from
   the public side.
7. **Symmetry.** Any `PriceBand`/`PriceIndex` endpoint or page available to a buyer role is
   available, byte-for-byte, to a supplier role for the same cell — enforced by having one
   Filament/API resource with role-based *field* hiding never used, and role gating only at
   the "can view this cell at all" level (there is none — all published bands are public).
8. **Outlier trimming.** `PriceMethodologyConfig.trimming` (e.g. `0.05` = trim top/bottom 5%)
   applied before computing `low`/`median`/`high` for a `PriceBand` — configurable, not
   hardcoded, per the brief's "admin-configurable" instruction.
9. **No recommendation language enforced as a lint, not just a style guide.** Any string
   rendered from a `PriceBand`/`PriceIndex` view must come from a fixed set of templates
   ("indicative — recent range: …") — never string-built from user input or a free-text
   admin field, so "you should charge…" cannot be typed into a template that later renders on
   a public page.

---

## 5. What writes a `PriceObservation` — today and what's missing

| Existing write path | File | Emits `PriceObservation` today? | What's missing to make it possible |
|---|---|---|---|
| Product save (create/update, `status = 'active'`) | `app/Filament/.../ProductResource.php` (staff-authored today — see `AUDIT.md` gap #3, suppliers cannot self-serve products) | **No.** No `Observer`, no event listener on `Product` at all — `find app/Observers` shows only `ArticleObserver`. | A `ProductObserver::saved()` (or a dedicated `ProductPriceObservationListener` on a `ProductSaved` event, mirroring the existing `RfqApproved`/`CompanyVerified` event pattern) that fires only when `price_amount` is non-null and `status = 'active'`; needs `basis`/`region` columns from §3 first, or it emits with both null (degraded, not blocked) |
| RFQ post | `app/Http/Controllers/.../RfqController.php` + `RfqTriageService` | **No.** RFQ posting has no price-observation hook, and per §3 above this is correct — an RFQ's `target_amount` is a buyer's budget ceiling, not a market price; §8.1 lists `quoted`/`transacted`, not `rfq_posted`, as sources | Nothing to build here — RFQ posting should **not** emit a `PriceObservation`; only the quotes it produces should |
| Quote submit (`status → 'submitted'`) | `app/Services/QuoteService.php` | **No.** No event fires on quote submission today. | `source = 'quoted'` observation per `quote_item`, on the transition to `submitted`; needs `quote_items.moisture_content` (§3) and a region source (§G3) |
| Quote/order accept (`orders` row created — `orders_quote_unique` index guarantees exactly one order per accepted quote) | `app/Services/OrderLifecycleService.php` | **No.** | `source = 'transacted'` observation per `order_item`, fired on `awarded_at` being set; this is the **highest-weight** source per §8.1 and the one most in need of the 30-day lag rule (§4.5) since an accepted order is unambiguously a completed deal between two identifiable parties until the lag masks it |
| `company_species` price update (catalogue "typical price" per species a company handles) | **No model, no resource.** `company_species` is a migration-only table — `find app/Models -iname "*Species*"` returns only `app/Models/Species.php`; there is no `CompanySpecies` Eloquent model and no Filament resource anywhere in `app/`. The table exists in the schema but nothing in the application writes to it. | **No — cannot emit from code that doesn't exist.** | Building a `CompanySpecies` model/resource is itself the prerequisite; once it exists, wire `source = 'listed'` on save when `price_amount` is non-null, plus the 5-column addition from §3 (`moisture_content`, `dimensions`, `unit`, `basis`, `region`) to reach full basis |
| Quote counter-offer (`quote_counter_offers`, `status = 'accepted'`) | migration docblock describes this as an append-only negotiation ledger | **No.** | Not a first-class §8.1 source, but an accepted counter-offer *becomes* the new quote total per the migration's own comment ("only agreement mints a revised Quote"), so it is covered transitively once quote-accept emission (row above) exists — no separate hook needed |
| Reference price entry (MINFOF *mercuriale*, ITTO FOB ranges, CTH staff market survey) | none — no admin UI, no seeder, no table | **No** — there is nothing to enter it into yet. | Needs `ReferencePriceSource` + a Filament resource for staff to key in `source = 'reference'` rows; this is manual entry by design per §8.1, not derived from another write path |
| Logistics/processing cost quote (Transport RFQ / processing RFQ) | none — §7 Transport RFQ and §4.2 processing RFQ are both MISSING per `docs/GAP_PLAN.md` Phase 1.5 | **No** — the source tables (`TransportQuote` etc.) do not exist yet. | Blocked on Phase 1.5 items 1.5.5/1.5.9/1.5.10 landing first; `source = 'logistics'`/`source = 'processing'` cannot be built before their source tables exist |

**Net:** zero of the six live write paths that could plausibly emit a `PriceObservation`
today does so. The two that can emit with their *current* schema unmodified (`orders`/
`order_items` on award, `quote_items` on submit minus `moisture_content`) are the ones to
build first — they are also the two the brief weights highest (`transacted`, then `quoted`).
