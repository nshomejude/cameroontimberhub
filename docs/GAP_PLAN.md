# Cameroon Timber Hub — Gap Plan

**Source of truth:** `CTH_Claude_Code_Build_Brief.md` · **Current state:** [`docs/AUDIT.md`](AUDIT.md)
**Date:** 2026-08-27

220 brief items assessed: 62 BUILT, 55 PARTIAL, 10 STUB, 93 MISSING. This plan covers
every non-BUILT item, grouped by the brief's own phases.

Effort is in **engineer-days** for a developer who knows this codebase. They are
estimates for sequencing, not commitments.

---

## Phase 0 — Foundations that unblock everything else

These are not in the brief's phase list, but four subsystems in Phases 1–3 each
independently require them. Building them first avoids three separate migrations of the
same tables. **Nothing else should start before these land.**

| # | Work | Why it is a blocker | Days |
|---|---|---|---:|
| 0.1 | ✅ **Polymorphic `Document` store — done.** `documents(owner_type, owner_id, type, ..., hash, prev_hash, verification_status)`, a `HasDocuments` trait, wired to `Species` as the first real consumer, plus `DocumentPolicy` (reusing the existing `documents.review` permission). Hash-chains per owner in upload order — verified empirically, a genuinely distinct 3-document chain (A→B→C, each `prev_hash` matching its predecessor). Scoped down from the original item: does NOT touch `company_documents`/`order_documents`/`rfqs.attachments` — see 0.1b. Plan: `docs/superpowers/plans/2026-08-27-polymorphic-document-store.md`. | — | 0 (done) |
| 0.1b | **Migrate `company_documents`/`order_documents` onto the polymorphic `documents` table.** `CompanyDocument` has 24 consumers (two Filament panels, 4 actions, 2 events, a scheduled expiry-reminder job, a notification, a policy, 3 services); `OrderDocument` has 4+. Both need updating in a dedicated, carefully tested pass — migrating a live subsystem in the same pass as building the new table would be exactly the "large refactor" brief rule 0.3 says to confirm before doing. Also generalize `DocumentReminderLog` (currently hard-FKs `company_document_id` NOT NULL) to a polymorphic `owner_type`/`owner_id` shape before `Document`-owned entities can get automated expiry reminders — confirmed this dependency directly: the existing `SendDocumentExpiryReminderJob` cannot be generalized without either duplicating its logic or building a second reminder pipeline, so it was correctly left untouched. | Carbon projects, sponsorship files, vehicles, drivers, products and artisans all need documents with expiry + verification once those entities exist. §3.2, §5, §6, §7. | 8 |
| 0.2 | ✅ **Polymorphic `Verification` — done.** `verifications`/`verification_checkpoints` (`entity_type`/`entity_id`), driving the brief's exact 8-stage sequence (`registered → company_info → business_docs → identity_kyc → forestry_legal_docs → compliance_review → verified → published`, `rejected` a terminal branch off `compliance_review`) via `VerificationFlowService`, plus `HasVerification` wired to `Product` (first real consumer) and `VerificationPolicy` (reusing `verification.review`). `needs_more_info` lives strictly at `VerificationCheckpoint::status`, never as a `Verification::stage` value — verified empirically by walking a real `Product` through the full sequence via tinker (registered→…→published) and a `requestMoreInfo`/`resubmit` pair that leaves `stage` unchanged while `needsMoreInfo()` flips true then false. Scoped down: does NOT touch `companies.status`/`verification_requests`/`CompanyStatusService`/`VerificationService` — see 0.2b. Plan: `docs/superpowers/plans/2026-08-27-polymorphic-verification.md`. | — | 0 (done) |
| 0.2b | **Migrate `Company` and `verification_requests` onto the polymorphic `Verification` framework.** `companies.status` (6-value CHECK) and `verification_requests.status` (4-value CHECK) are read or written by 27 files — `CompanyStatusService`, `VerificationService`, 3 `Actions\Verification\*` classes, the `VerificationRequests` Filament resource, `VerificationRequestPolicy`, `PendingVerificationsWidget`, the exporter panel's `EditCompany` page, and 8 Blade views branching on a company's verification/badge state. Needs a dedicated, carefully tested migration pass — badge issuance and `CompanyVerified`/`BadgeIssued` events must keep firing at the same points in the new 8-stage sequence — not bundled into building the `Verification` table itself. See `docs/superpowers/plans/2026-08-27-polymorphic-verification.md`'s "Scope decision" section for the full file inventory. | §9's single verification framework only genuinely covers "supplier" once `Company` is on it too. | 6 |
| 0.3 | ✅ **Feature flags — done.** Laravel Pennant (`database` driver) installed and proven against a real gate: `DEMO_LOGINS_ENABLED` migrated off a controller-level `config()` check onto a Pennant flag enforced by the `demo.logins.enabled` route middleware (`EnsureDemoLoginsEnabled`) — the Blade toggle is now purely cosmetic, confirmed by a test that never touches the Blade template. Admin-override precedence over the config-seeded default verified against Pennant's own `DatabaseDriver` source: once a flag row exists, `resolve()` is never called again for that scope. `carbon-trading-enabled`/`regulatory-cleared`/`telematics-feed-enabled` flag classes are explicit future work — each should land in the same commit as the feature it gates. Plan: `docs/superpowers/plans/2026-08-27-feature-flags.md`. | — | 0 (done) |
| 0.4 | ✅ **Persisted consent records — done.** `consents(subject_type, subject_id, purpose, scope, granted_at, revoked_at, evidence)`, a `ConsentPurpose` backed enum (CHECK-constrained, one case today: `rfq_exporter_sharing`), a `Consent` model, and a `HasConsents` trait wired to `Rfq` as the first real consumer. `IntakeService::createRfq()` now persists a `Consent` row from the RFQ wizard's "share with verified exporters and contact me by email" checkbox instead of discarding it (`RfqController::store()` forwards the checkbox value through); `scope` captures both grants bundled in that checkbox (`shared_with`, `contact_channel`) rather than losing the distinction. Revocation is enforced against the real data feed it governs, not a synthetic one: `RfqTriageService::route()` refuses to create new `RfqCompany` routings for an RFQ whose consent has been revoked (proven empirically — an active-consent RFQ routes 1, a revoked-consent RFQ routes 0, and an RFQ with no Consent row at all still routes normally, matching pre-existing behaviour). Deliberately scoped to the RFQ wizard only — the contact/inquiry forms' own consent checkboxes and the mobile API RFQ path are tracked as 0.4b/0.4c below. Plan: `docs/superpowers/plans/2026-08-27-persisted-consent.md`. | — | 0 (done) |
| 0.4b | **Wire the contact/inquiry forms' consent checkboxes onto the `Consent` ledger.** `ContactController::store()` and `InquiryController::store()` each validate their own `consent` checkbox (`app/Http/Controllers/Public/ContactController.php:141`, `app/Http/Controllers/Public/InquiryController.php:30`) and discard it exactly as the RFQ wizard did before item 0.4. Same fix, different subject (`CompanyInquiry` for the inquiry form; the contact form has no persisted subject today — decide whether it needs one, or whether `Consent` should support a `null` subject for "no entity to attach to yet" flows). | Same GDPR-style consent gap as the RFQ wizard, just on two more flows. | 1 |
| 0.4c | **Mobile API RFQ consent.** `StoreRfqRequest` (`app/Http/Requests/Api/V1/StoreRfqRequest.php:14-16`) strips the `consent` rule entirely because "the native client presents its own consent copy" — meaning no consent is collected or recorded for API-created RFQs today, and `IntakeService::createRfq()`'s `$consentGiven` parameter (item 0.4) defaults `false` on that path. Requires the mobile client to actually submit a consent flag before this can be wired without fabricating consent that was never given. | Same consent gap as the RFQ wizard, blocked on mobile client work rather than a code decision. | 0.5 |
| 0.5 | **Separation of duties + tamper-evident audit.** Add originator≠approver enforcement, dual-authorisation by amount band, a `compliance_officer` block capability, and hash-chaining on the activity log. | §6.9 requires it in code, not policy. Also gives §3.9 its hash-chain primitive for free. | 6 |
| 0.6 | **`Organisation.type` migration.** `companies.supplier_type` (5 values) → 10 types incl. processor, manufacturer, artisan, retailer, logistics, carbon_developer, financier, training_provider. Map `exporter`/`trader` — they have no target equivalent, so **decide before migrating**. | Every §4 directory and §6/§7 role depends on it. | 4 |
| 0.7 | **RBAC rebuild.** Seven of the brief's nine roles do not exist. Replace the staff-only role matrix with the brief's set, and remove `EnsureBuyerAccount`'s single-role assumption — the brief requires one account to hold several roles (a sawmill both supplies and buys processing). | §3.1. Blocks every new persona. | 5 |

**Phase 0 total: ~30.5 days remaining** (0.1 and 0.3 done at 0 days each; 0.2 done at 0 days with its follow-up split out as 0.2b at 6 days — the same 6-day estimate the original 0.2 carried, so no net change there; 0.1b split out at 8 days, +3 over 0.1's original estimate; 0.4 done at 0 days with its follow-ups split out as 0.4b + 0.4c at 1.5 days total, -1.5 days under 0.4's original 3-day estimate). Do not compress this. Every day skipped here is repaid with
interest in Phases 1–2.

---

## Phase 1 — Complete the core trade platform

Acceptance: *supplier registers → KYC → verified → publishes product with documents →
receives RFQ → quotes → negotiates → order → payment → export docs → receipt whose QR
resolves to a public verification page.* Mirror journey on mobile for buyers.

| # | Work | Status now | Days |
|---|---|---|---:|
| 1.1 | **Product ID + QR + public product verification page.** `CTH-CMR-{SPECIES}-{seq}`, QR library, `/verify/product/{id}`, share card. | MISSING — zero QR code in repo | 6 |
| 1.2 | **Integrity hash-chain.** `integrity_records(hash, prev_hash, …)`; chain receipts; surface on the verify page; optional public-chain anchor adapter behind a flag. | MISSING — `receipts` has no hash column | 5 |
| 1.3 | **Product documents.** Per-product datasheet, legal-origin, FSC/PEFC, phytosanitary — each with uploaded/verified/expired status. Stop filling the product page's "Certifications" block from the *supplier's* badges. | MISSING — and currently misleading | 4 |
| 1.4 | **Supplier self-service products.** Products resource in the exporter panel; split `products.manage` into own-vs-any. | MISSING — staff enter every listing | 4 |
| 1.5 | **RFQ `type` + matching engine.** Add `rfqs.type` (timber/manufacturing/processing/project); replace admin-picked company arrays with scored matching on species, category, location, capacity, verification. | MISSING | 8 |
| 1.6 | **`Payment` table.** Extract 6 scalar columns off `orders`. **Migrate before more payment data accrues — partial-payment history is unrecoverable afterwards.** Invoices. | PARTIAL | 6 |
| 1.7 | **`Shipment` entity.** Extract ~12 columns off `orders`. Export document checklist. | PARTIAL | 5 |
| 1.8 | **Order status + disputes.** Add `ready` and `disputed`; build the dispute entity, state and UI (none exists). | PARTIAL | 5 |
| 1.9 | **i18n EN/FR.** Extract all strings; French translation; locale switching; XAF primary with USD/EUR for export. | MISSING in practice — one 40-line English file | 12 |
| 1.10 | **Reputation metrics.** Response rate, on-time delivery, documentation compliance, dispute history. | PARTIAL | 4 |
| 1.11 | **Notifications.** In-app + email for RFQ match, quote, order events. SMS/WhatsApp optional. | PARTIAL | 5 |
| 1.12 | **Unified search** across products, suppliers, species, and later processors/projects. | PARTIAL | 4 |
| 1.13 | **Structured price capture completion + first `PriceObservation` emitters (rule 0.7, §8.1).** New `PriceBasis`/`PriceVolumeBand` enums; add `Other` case to `RfqIncoterm` to match its own CHECK constraint; add `products.basis`/`products.region`, `company_species.moisture_content`/`dimensions`/`unit`/`basis`/`region`, `quote_items.moisture_content`, `order_items.moisture_content`; build the missing `CompanySpecies` model + Filament resource (currently schema-only, no code touches it); `price_observations` table + model; emit on order award (`transacted`) and quote submit (`quoted`) — the two sources buildable against today's schema with the columns above. Full schema in `docs/PRICE_DATA_STANDARD.md`. | MISSING — no free-text to migrate (rule 0.7's stated first step finds nothing), but `PriceObservation` and several basis columns don't exist | 10 |

**Phase 1 total: ~78 days.**

### Carried-over defects (fold into Phase 1, ~2 days)

Not brief items, but live and damaging:
- Species meta descriptions truncate mid-word at ~303 chars (all 52 pages).
- Supplier JSON-LD publishes `sales@africanwood.example` beside a "Verified Exporter"
  credential claim — **directly undermines the trust layer**. Suppress placeholder
  contact data from schema, or seed real values.
- `og:type` hardcoded `website`; duplicate `<h1>` on 8 pages (homepage's two differ).

---

## Phase 1.5 — Domestic marketplace + logistics foundation

| # | Work | Days |
|---|---|---:|
| 1.5.1 | **`Category` tree** replacing the flat 16-value `product_type` CHECK: raw / secondary processed / finished / construction / residue / equipment, plus sector collections. Backfill existing products. | 7 |
| 1.5.2 | **Buy Cameroon Wood** experience — domestic search that never requires export vocabulary, domestic filters, delivery zones. | 8 |
| 1.5.3 | **Transformation Network** — processor/manufacturer directory, separate from timber suppliers; "Find a Processor / Manufacturer" entry points. | 8 |
| 1.5.4 | **`Capacity`** model `{capability, quantity, unit, period}` + capacity search. | 4 |
| 1.5.5 | **Manufacturing RFQ + Local Procurement Hub + Project RFQ** (multi-line) over the `rfqs.type` work from 1.5. | 8 |
| 1.5.6 | **`Inventory`** per product/location with "Available now", decremented on order. | 4 |
| 1.5.7 | **Made in Cameroon** badge, qualifying rules, filter, landing page; product QR shows transformation history. | 5 |
| 1.5.8 | **Artisan / professional profiles** with portfolio. | 6 |
| 1.5.9 | **Logistics directory + verification** (trusted / tech-enabled tiers) over the Phase 0 verification framework. | 6 |
| 1.5.10 | **Transport RFQ + booking → `Shipment`**, digital waybill with QR linking cargo product IDs. | 7 |
| 1.5.11 | **Manual checkpoint tracking + public tracking page** (token link, photo + GPS at update). No telematics yet. | 5 |
| 1.5.12 | **Fleet & driver registry** with document expiries feeding alerts. | 5 |
| 1.5.13 | **Domestic content** for the Knowledge Centre — §4.10's content hub list. Machinery already exists; this is editorial. | 6 |

**Phase 1.5 total: ~79 days.**

---

## Phase 2 — Tracking engine, sponsorship, carbon registry

| # | Work | Days |
|---|---|---:|
| 2.1 | **Tracking engine.** Traccar REST + WebSocket, device↔vehicle mapping, geofences, OwnTracks driver onboarding, OpenGTS import adapter, normalised `Position`/`TrackingEvent`, downsampling, live shipment map, geofenced delivery confirmation, trip records. Needs a broadcasting driver — none configured today. | 25 |
| 2.2 | **Forest Sponsorship — application to committee.** Operator application (incl. GeoJSON boundary), sponsor KYB onboarding, screening fees with the mandatory disclosure acknowledged and timestamped, due-diligence checklist, configurable scorecard, committee queue with conflicts and minutes. | 22 |
| 2.3 | **Funding agreements + tranches.** Structures with `regulatory_cleared` enforced at the authorisation layer, milestone schedule, evidence requirements, dual-authorised disbursement. | 15 |
| 2.4 | **Repayment ledger + waterfall + dashboards.** Typed cash/wood/set-off/adjustment entries, frozen wood valuation, per-sponsor pro-rata allocation, configurable waterfall, and four consistent views (admin all-sponsorships, sponsor, operator, public opportunity) plus settlement statement PDF. **The hardest single item in the brief.** | 25 |
| 2.5 | **Field monitoring** — geotagged evidence, volume reconciliation, automated alerts, auto-freeze on material incident. | 10 |
| 2.6 | **Carbon project registry** — profile, GeoJSON boundary, status machine, `CTH-CARB-…` ID + QR + public page. | 12 |
| 2.7 | **Credit lifecycle** — separate registered/validated/verified/issued/available/sold/retired counters that are never conflated in UI; procurement flow; retirement certificates publicly verifiable. Trading behind a flag. | 14 |
| 2.8 | **Benefit-sharing ledger.** | 6 |
| 2.9 | **Residue exchange · equipment marketplace · finance directory.** | 12 |
| 2.10 | **Growth pathway levels · escrow/milestone payments · market insights aggregation.** | 12 |
| 2.11 | **Price Intelligence — reference prices, indicative bands, landed-cost estimator (§8.1 Phase 2).** `ReferencePriceSource` + staff-entry Filament resource for MINFOF *mercuriale*/ITTO FOB ranges; extend `PriceObservation` emission to `listed` (product save, `company_species` save once 1.13 lands) and `logistics`/`processing` (once Transport RFQ / processing RFQ exist per 1.5.5/1.5.9/1.5.10); `PriceBand` computation enforcing the N≥5-sellers/M≥10-observations/30-day-lag rules from `docs/PRICE_DATA_STANDARD.md` §4; product-page and RFQ-creation "recent quotes ranged…" surfacing, shown identically to buyers and sellers; landed-cost estimator (timber + processing + logistics + documentation fees, labelled estimate); nightly aggregation job extending the existing `routes/console.php` schedule pattern. | 14 |

**Phase 2 total: ~167 days.**

---

## Phase 3 — Intelligence & advanced

Route risk map · trip/driver/fleet reports · safety scores with disputes and reviews ·
incident flow · insurance-partner directory · cargo-insurance quotes · consent-based
telematics feed · Wood Economy dashboard · carbon market intelligence · MRV integrations ·
calculators · Academy courses & apprenticeships · warehousing · public-chain anchoring.

**Price indices, alerts and data product (§8.1 Phase 3, ~10 days):** `PriceIndex` monthly
series per species/product/region with export-vs-domestic split and charts; `PriceAlert`
for watched species/products with a notification hook onto the existing notification
system (item 1.11); subscription reports/API data product for industry, banks, insurers
and policymakers, feeding the Wood Economy dashboard (§8.2); legal review of the
methodology and publication policy against Cameroon/CEMAC competition rules, and a
published methodology page, before public launch — a business/legal gate, not engineering,
but the launch checklist item belongs here.

**Phase 3 total: ~100 days.**

---

## PEFC Certification API integration

Requested addition. It fits the trust layer (§3.2 product documents, §3.4 verification,
§4.5 Made in Cameroon, §5.10 green manufacturer).

**What the API actually is** (from pefc.org/resources/certification-api, fetched
2026-08-27): real-time lookup of PEFC certification and licence data — verify an
organisation's certification status, retrieve linked organisation and certification
details, monitor validity over time.

**The blocker: it is access-gated.** There are no public endpoint paths, no published
authentication scheme, and no public rate limits. The page's own call to action is
*"Request access here… Approved users will receive API access details and onboarding
support."* **Someone must apply to PEFC and be approved before any integration can be
written.** I cannot design against endpoints I have not seen.

Plan, in the only order that works:

| # | Work | Depends on | Days |
|---|---|---|---:|
| P.1 | **Apply for API access.** Business action, not engineering. | — | — |
| P.2 | **`CertificateVerifier` interface + manual adapter.** Ship now, without the API: a certificate record (scheme, number, holder, scope, issued/expiry), admin-verified, with a deep link to PEFC's public *Find Certified* search for the reviewer to check by hand. Honest status values: `unverified` / `manually_verified` / `expired`. | 0.1 Document store | 4 |
| P.3 | **PEFC adapter behind the interface**, once credentials exist: scheduled revalidation, status change → alert, cached responses, graceful degradation to manual when the API is unavailable. | P.1 approved | 6 |
| P.4 | **Surface on product, supplier and Made in Cameroon pages** — with the source and check date shown, never a bare "certified" badge. | P.2 | 3 |

Two rules for this integration, consistent with the platform's existing discipline:
- **Never render an unverified certificate as verified.** A number typed by a supplier is
  a claim; only an API response or a named reviewer's check makes it evidence.
- **Always show the check date.** A certificate verified six months ago may have lapsed;
  a stale "verified" badge is worse than none. This mirrors the existing rule that a
  null `eudr_risk_note` renders as "not yet assessed" rather than silence.

FSC has an equivalent public API and should follow the same interface — worth building
P.2 with two adapters in mind rather than hardcoding PEFC.

---

## Totals and sequencing reality

| Phase | Days |
|---|---:|
| Phase 0 foundations | 31 |
| Phase 1 core completion | 78 |
| Phase 1.5 domestic + logistics | 79 |
| Phase 2 tracking, sponsorship, carbon, price intelligence | 167 |
| Phase 3 intelligence | 100 |
| PEFC integration | 13 |
| **Total** | **~468 engineer-days** |

That is roughly **two engineer-years**, or about 7 months with a team of three. The
brief's rule 4 — do not start a later phase until the earlier one is functional
end-to-end — is the right constraint and should hold.

**Recommended immediate sequence:** Phase 0 in full → Phase 1 items 1.1–1.4 (they close
the Phase 1 acceptance criterion and the trust-layer gaps) → 1.6 `Payment` extraction
(urgent: it gets harder every day data accrues) → then the rest of Phase 1.

**Three items need a decision before code:**
1. `exporter`/`trader` have no equivalent in the target 10-type model (0.6).
2. Whether sponsorship is offered at all before COSUMAF/CEMAC counsel signs off — the
   flags can be built either way, but the answer changes Phase 2's ordering.
3. Whether PEFC access has been requested (P.1), since P.3 cannot start without it.
