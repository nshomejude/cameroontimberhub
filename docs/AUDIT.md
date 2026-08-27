# Cameroon Timber Hub — Current-State Audit

**Audited against:** `CTH_Claude_Code_Build_Brief.md` (Sections 3–9)
**Date:** 2026-08-27
**Codebase at:** commit `08d8817` (production parity)

This is the summary map. The evidence — item-by-item tables with file paths and line
numbers — lives in two companion documents, and they are the authority:

- [`docs/audit/CORE_TRADE_AUDIT.md`](audit/CORE_TRADE_AUDIT.md) — Sections 3 and 9
- [`docs/audit/NEW_PILLARS_AUDIT.md`](audit/NEW_PILLARS_AUDIT.md) — Sections 4 to 8

## Headline numbers

| Scope | BUILT | PARTIAL | STUB | MISSING | Items |
|---|---:|---:|---:|---:|---:|
| §3 Core trade + §9 Cross-cutting | 62 | 45 | 9 | 44 | 160 |
| §4 Domestic market | 0 | 6 | 0 | 15 | 21 |
| §5 Carbon & climate | 0 | 0 | 0 | 12 | 12 |
| §6 Forest sponsorship | 0 | 3 | 0 | 12 | 15 |
| §7 Logistics & tracking | 0 | 0 | 1 | 7 | 8 |
| §8 Intelligence | 0 | 1 | 0 | 3 | 4 |
| **Total** | **62** | **55** | **10** | **93** | **220** |

**28% of the brief is built.** The built quarter is concentrated almost entirely in
§3 — the public site, marketplace, supplier directory, RFQ→quote→order→receipt
engine, messaging, and the Filament admin. Sections 5, 6 and 7 are close to
greenfield.

## What is genuinely strong

Worth stating plainly, because the gap plan should extend these rather than touch them:

- **The RFQ → Quote → Order → Receipt engine.** RFQ wizard with triage and risk
  scoring, quote service with counter-offers, contract acceptance, a guarded
  `OrderLifecycleService`, receipts with a public `/verify/{token}` page. This is
  the single largest reusable asset in the codebase.
- **The admin platform.** The brief says it "must be built"; it already exists and is
  the most complete module in §3 — 16 Filament resources, a verification queue,
  moderation, and audit logging via `spatie/activitylog`.
- **The Knowledge Centre and species catalogue.** §4.10 is substantially satisfied
  already: 52 species with workability, drying, treatments, grades, EUDR notes and
  regional availability; eleven hub pillars; a glossary; an article import pipeline;
  sitemap and `llms.txt`. It needs domestic content and cross-links, not new machinery.
- **Receipt verification.** `app/Services/ReceiptVerifier.php` and the public verify
  page are well built — which is precisely what masks gap #1 below.

## The five gaps most likely to be wrongly assumed BUILT

1. **No QR codes or product IDs exist anywhere.** Zero matches for `qr`/`barcode`
   across `app/`, `resources/views/` and `composer.json`; no QR library installed.
   Products have no `CTH-CMR-…` identifier and there is no public product
   verification page. The excellent receipt verifier disguises this. The Phase 1
   acceptance criterion — "a receipt whose QR resolves to a public verification
   page" — is **unmet**.
2. **No integrity or hash-chain record.** `receipts` has no hash column; there is no
   `prev_hash`, no signature, no `integrity_records` table. `ContractAcceptance` is a
   single-row check whose own docblock says it is *not* a signature.
3. **Suppliers cannot manage their own products.** No Products resource in the
   exporter panel, and `ProductPolicy` gates create/update/delete behind the
   staff-only `products.manage`. Every listing is entered by platform staff today.
4. **No product documents.** `Product` has only `company()`, `species()`, `images()`.
   The public product page fills its "Certifications" block from the *supplier's*
   badges, and `products.certification` is unverified free text. §3.2 requires
   per-product datasheets, legal-origin, FSC/PEFC and phytosanitary documents each
   with their own status.
5. **No RFQ matching engine and no RFQ `type` column.** `RfqTriageService::route()`
   takes an admin-selected array of company IDs — no scoring, no species/location/
   capacity query, no auto-notification. Manufacturing, Processing and Project RFQs
   have nowhere to live.

Honourable mention: **i18n is effectively absent.** `lang/` holds one 40-line English
file and four Blade files call `__()`. There is no French anything, despite EN/FR
being a Phase 1 requirement in a bilingual country.

## Data-model conflicts requiring a migration path

These are the places where §10's target model **conflicts** with what exists, so they
need migration rather than clean addition. Per the brief's rule 3, these are proposed
rather than executed:

| Existing | Target (§10) | Why it blocks |
|---|---|---|
| `verification_requests.company_id`, `company_documents.company_id` (hard FKs) | polymorphic `Verification(entity_type, entity_id)`, `Document(owner)` | Blocks verifying **any** §4–§7 entity: processor, artisan, project, vehicle, carrier |
| Three document stores: `company_documents`, `order_documents`, `rfqs.attachments` jsonb | one central `Document` | Expiry, hashing and verification status can't be applied uniformly |
| `products.product_type` — flat 16-value CHECK | `Category(tree: raw\|processed\|finished\|construction\|residue\|equipment)` | Needs a table plus backfill; no finished goods or sector collections today |
| `companies.supplier_type` — 5-value CHECK | 10-type `Organisation.type` | `exporter`/`trader` have no target equivalent; processor/artisan/retailer/financier/carbon_developer absent |
| Payment as 6 scalar columns on `orders` | `Payment` table | **Partial-payment history before migration is unrecoverable** |
| Shipment as ~12 columns on `orders` | `Shipment` entity | Must be extracted before §7 logistics can attach to it |
| `orders.status` CHECK | adds `ready`, `disputed` | Disputes have no entity, state or UI anywhere |
| Two verification machines (`companies.status` + `verification_requests.status`) | one 8-state machine ending `published` | `needs_more_info` exists only per-document today |

## Governance and regulatory readiness

The brief gates three subsystems on primitives that **do not exist**:

- **No feature-flag infrastructure.** No Pennant, nothing equivalent. Carbon trading,
  each sponsorship structure's `regulatory_cleared` state, and the telematics feed are
  all supposed to be flag-gated. `regulatory_cleared` in particular must be enforced at
  the authorisation layer — a UI-only gate is not a gate.
- **Consent is never persisted.** It is currently an RFQ checkbox that is validated and
  discarded. §7.6 requires per-driver, per-vehicle, per-policy consent records that are
  revocable and inspectable.
- **Audit log is not tamper-evident and has no separation of duties.**
  `spatie/activitylog` is a real foundation, but §6.9 requires originator ≠ approver,
  dual authorisation by amount band, and a compliance officer who can block at any
  stage. None of that concept exists.

**These are engineering gates, not legal clearance.** The code can enforce them; whether
the underlying activity is permitted under COSUMAF/CEMAC (sponsorship), Cameroon's
carbon-rights regime, or CIMA and data-protection law (telematics) is a question for
counsel and must be answered before those features are enabled for real users.

## Known open defects in the built quarter

Not from the brief, but they should not be buried under new scope:

- Species meta descriptions truncate mid-word at ~303 chars across all 52 species pages.
- Supplier profiles publish placeholder contact data (`sales@africanwood.example`) in
  live `Organization` JSON-LD, directly beside a `hasCredential: "Verified Exporter"`
  claim — this actively undermines the trust layer §3.9 makes central.
- `og:type` is hardcoded `website` sitewide; duplicate `<h1>` on 8 pages, with the two
  on the homepage saying different things.
