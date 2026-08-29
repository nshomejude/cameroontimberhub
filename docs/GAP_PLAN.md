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
| 0.1b | ⏳ **Partially done — prerequisites landed, consumer migration not started.** A dispatched agent correctly BLOCKED before writing consumer code: `CompanyDocument` carries `document_type_id`/`visibility`/`sigif_fields` with no equivalent on `documents` — migrating any consumer would have silently dropped data. Resolved additively: `documents` extended with all three (nullable, `database/migrations/2026_08_29_100020_add_company_document_fields_to_documents_table.php`). Also done: `DocumentReminderLog` generalized to a polymorphic `document_owner_type`/`document_owner_id` (was hard-FK'd to `company_documents`), `SendDocumentExpiryReminderJob` now logs polymorphically for `Document`-owned entities too, and `documents:backfill-from-legacy` — an idempotent, upload-order-correct `CompanyDocument`/`OrderDocument` → `Document` copy command — exists and is tested. **Not done:** the actual 24+4 consumer migration (Filament panels, actions, events, notification, policy, services) — that's the bulk of the original estimate, still ahead. | Carbon projects, sponsorship files, vehicles, drivers, products and artisans all need documents with expiry + verification once those entities exist. §3.2, §5, §6, §7. | 6 remaining (2 of 8 spent on prerequisites) |
| 0.2 | ✅ **Polymorphic `Verification` — done.** `verifications`/`verification_checkpoints` (`entity_type`/`entity_id`), driving the brief's exact 8-stage sequence (`registered → company_info → business_docs → identity_kyc → forestry_legal_docs → compliance_review → verified → published`, `rejected` a terminal branch off `compliance_review`) via `VerificationFlowService`, plus `HasVerification` wired to `Product` (first real consumer) and `VerificationPolicy` (reusing `verification.review`). `needs_more_info` lives strictly at `VerificationCheckpoint::status`, never as a `Verification::stage` value — verified empirically by walking a real `Product` through the full sequence via tinker (registered→…→published) and a `requestMoreInfo`/`resubmit` pair that leaves `stage` unchanged while `needsMoreInfo()` flips true then false. Scoped down: does NOT touch `companies.status`/`verification_requests`/`CompanyStatusService`/`VerificationService` — see 0.2b. Plan: `docs/superpowers/plans/2026-08-27-polymorphic-verification.md`. | — | 0 (done) |
| 0.2b | ✅ **Done at reduced (read-only mirror) scope.** A second dispatched agent found a further blocker the first correctly stopped short of: `VerificationStage` (item 0.2's enum) has no case for `suspended`/`archived`, and `verification_requests`' real workflow is flat (`pending → in_review → approved|rejected`) while `VerificationStage` encodes 5 sequential sub-stages with no real checkpoints behind them for `Company` — a stage-by-stage cutover would either corrupt the shared enum or fabricate review history that never happened. **Adopted instead:** `Company` gets `HasVerification` and a `Verification` row kept in sync by a new `CompanyVerificationMirror` service, called from all 4 real mutation points (`VerificationService::submit/approve/reject`, `VerifyCompany::execute`) *after* the real transition succeeds, wrapped so a mirror failure is logged and swallowed — never able to block or reverse a real status change or badge issuance. A new `VerificationFlowService::fastForward()` primitive jumps a `Verification` straight to a target stage in one explicitly-labelled checkpoint, instead of walking `FORWARD` one hop at a time (which doesn't fit `Company`'s flat review). `companies.status`, `verification_requests`, `CompanyStatusService`, `VerificationService`'s existing behaviour, and `BadgeService`'s per-badge-type issuance loop are all untouched — badge issuance is explicitly out of scope, since it's N independent document checks, not one state, and cannot be represented as a stage transition even in principle. `suspended`/`archived`/re-submission-after-rejection have no mirror representation yet (documented, not silently dropped — see 0.2c). Idempotent backfill command `companies:backfill-verification-mirror` covers pre-existing companies. Verified empirically via a real tinker walkthrough: a `Draft` company approved through `ApproveVerificationRequest` produced `{"issued":["verified_company"],"skipped":[]}`, `activeBadges()` = `["verified_company"]`, `status` = `verified`, and the mirror landed at `stage=verified` in exactly 1 checkpoint (`status=approved`, notes `"Mirrored: company verification approved (verification_requests / VerifyCompany)"`); a second company verified via `VerifyCompany::execute` (the badge-less path) mirrored to `stage=verified` with `activeBadges()->count()=0`, confirming both real dispatch sites mirror correctly while keeping their genuinely different badge behaviour intact. Full suite: 874/875 passing (the 1 failure, `KnowledgeHubTest`, belongs to concurrent unrelated work on domestic Knowledge Centre content, not this item). Plan: `docs/superpowers/plans/2026-08-29-verification-consumer-migration-v2.md`. | §9's single verification framework now has `Company` present for future cross-entity reporting, without corrupting the shared enum or fabricating review history that never happened. | 0 (done) |
| 0.2c | **Should `VerificationStage` ever gain `Suspended`/`Archived` cases, or should `Company`'s full lifecycle simply never be represented in the shared framework beyond 0.2b's read-only mirror?** Deferred by 0.2b rather than decided: `suspended→verified` reactivation and `rejected→pending` re-submission have no mirror representation today (`CompanyVerificationMirror` has no `suspended()`/`archived()`/`resubmitted()` methods, deliberately). Needs a product decision on whether the generic framework should bend for one consumer's real shape, or whether `Company` simply stays coarser than `Product` in this reporting layer permanently. | Only matters once a cross-entity "everything mid-verification" report is actually built and someone notices suspended/archived companies aren't reflected in it. | TBD — needs a product decision first |
| 0.3 | ✅ **Feature flags — done.** Laravel Pennant (`database` driver) installed and proven against a real gate: `DEMO_LOGINS_ENABLED` migrated off a controller-level `config()` check onto a Pennant flag enforced by the `demo.logins.enabled` route middleware (`EnsureDemoLoginsEnabled`) — the Blade toggle is now purely cosmetic, confirmed by a test that never touches the Blade template. Admin-override precedence over the config-seeded default verified against Pennant's own `DatabaseDriver` source: once a flag row exists, `resolve()` is never called again for that scope. `carbon-trading-enabled`/`regulatory-cleared`/`telematics-feed-enabled` flag classes are explicit future work — each should land in the same commit as the feature it gates. Plan: `docs/superpowers/plans/2026-08-27-feature-flags.md`. | — | 0 (done) |
| 0.4 | ✅ **Persisted consent records — done.** `consents(subject_type, subject_id, purpose, scope, granted_at, revoked_at, evidence)`, a `ConsentPurpose` backed enum (CHECK-constrained, one case today: `rfq_exporter_sharing`), a `Consent` model, and a `HasConsents` trait wired to `Rfq` as the first real consumer. `IntakeService::createRfq()` now persists a `Consent` row from the RFQ wizard's "share with verified exporters and contact me by email" checkbox instead of discarding it (`RfqController::store()` forwards the checkbox value through); `scope` captures both grants bundled in that checkbox (`shared_with`, `contact_channel`) rather than losing the distinction. Revocation is enforced against the real data feed it governs, not a synthetic one: `RfqTriageService::route()` refuses to create new `RfqCompany` routings for an RFQ whose consent has been revoked (proven empirically — an active-consent RFQ routes 1, a revoked-consent RFQ routes 0, and an RFQ with no Consent row at all still routes normally, matching pre-existing behaviour). Deliberately scoped to the RFQ wizard only — the contact/inquiry forms' own consent checkboxes and the mobile API RFQ path are tracked as 0.4b/0.4c below. Plan: `docs/superpowers/plans/2026-08-27-persisted-consent.md`. | — | 0 (done) |
| 0.4b | ✅ **Inquiry-form consent — done.** `CompanyInquirySharing` consent purpose added to the `Consent` ledger (additive CHECK-constraint extension), `HasConsents` wired onto `CompanyInquiry`, and `IntakeService::createInquiry()` now persists a `Consent` row from the inquiry form's checkbox instead of discarding it — mirrors item 0.4's RFQ-wizard fix exactly. Plan: `docs/superpowers/plans/2026-08-28-inquiry-consent.md`. | — | 0 (done) |
| 0.4b-ii | ✅ **Contact-form consent — done.** Schema decision made: persist a `ContactMessage` model (`contact_messages` table) rather than making `consents.subject_id` nullable — every other public intake form already persists its submission, so the contact form was the actual inconsistency. `ContactMessageSharing` consent purpose added (additive CHECK-constraint extension), `HasConsents` wired onto `ContactMessage`, and `IntakeService::createContactMessage()` now persists both the message and a `Consent` row from the form's checkbox instead of discarding it — mirrors 0.4/0.4b exactly. Plan: `docs/superpowers/plans/2026-08-29-contact-consent.md`. Commits: c81e7cb, 30cb1d7. | — | 0 (done) |
| 0.4c | **Mobile API RFQ consent.** `StoreRfqRequest` (`app/Http/Requests/Api/V1/StoreRfqRequest.php:14-16`) strips the `consent` rule entirely because "the native client presents its own consent copy" — meaning no consent is collected or recorded for API-created RFQs today, and `IntakeService::createRfq()`'s `$consentGiven` parameter (item 0.4) defaults `false` on that path. Requires the mobile client to actually submit a consent flag before this can be wired without fabricating consent that was never given. | Same consent gap as the RFQ wizard, blocked on mobile client work rather than a code decision. | 0.5 |
| 0.5 | ✅ **Hash-chained audit log — done.** `activity_log.hash`/`prev_hash`, computed on every write via `ChainedActivity` (a subclass registered as `spatie/laravel-activitylog`'s `activity_model`, so every existing and future `activity()` call site is chained automatically), plus `activitylog:verify-chain` to detect tampering. Scoped down from the full item — see 0.5b. Plan: `docs/superpowers/plans/2026-08-28-hash-chained-audit-log.md`. | — | 0 (done) |
| 0.5b | **Forest Sponsorship separation of duties.** The rest of §6.9 — originator≠approver enforcement, `compliance_officer` block capability, dual-authorisation by amount band, committee-decision conflict recording, documented-exception approval. All of it governs the Forest Sponsorship & Strategic Investment program (§6), which doesn't exist in the codebase yet. Building enforcement for workflows that don't exist would fabricate both halves. | §6.9 requires it in code once §6 exists; premature before then. | Blocked — depends on the Forest Sponsorship subsystem (§6) existing first |
| 0.6 | **`Organisation.type` migration.** `companies.supplier_type` (5 values) → 10 types incl. processor, manufacturer, artisan, retailer, logistics, carbon_developer, financier, training_provider. Map `exporter`/`trader` — they have no target equivalent, so **decide before migrating**. | Every §4 directory and §6/§7 role depends on it. | 4 |
| 0.6b | **BLOCKED on 0.7.** ~~Resolve `exporter`/`trader` → `Organisation.type` mapping and cut the 12 `supplier_type` consumers over.~~ **Decided:** Option 3 was chosen (see `docs/superpowers/plans/2026-08-27-organisation-type-migration.md`'s "Scope decision") — `exporter`/`trader` are left permanently unmapped (`type = NULL`), treated as a capability/role rather than a taxonomic type, deferred to the RBAC rebuild (0.7). This means cutting the 12 `supplier_type` consumers over to `type` **cannot happen yet**: 7 of 13 demo companies (5 exporter + 2 trader) would lose their public-directory facet entirely, since they have no `type` value and never will under Option 3. The real next step is 0.7 introducing a role/capability model that captures "exports"/"trades" as an attribute alongside `type`, at which point the 12 consumers can migrate to a combination of `type` + that new role field. Re-evaluate this row once 0.7 lands — do not attempt the migration before then. | Blocked, not skipped: `supplier_type`/`SupplierType` staying in place is now a considered consequence of the Option 3 decision, not an oversight. | Blocked — depends on 0.7 |
| 0.7 | ✅ **RBAC account roles — done (partial).** Seeded 7 of the brief's §3.1 account-capability roles (`buyer`, `supplier`, `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner` — `verifier` already covered by the existing `verification_officer` staff role), distinct from the staff-panel roles. `EnsureBuyerAccount` no longer unconditionally redirects a company-owning user away from buyer-only routes — a user holding both `supplier` and `buyer` can now reach both, matching the brief's "a sawmill both supplies and buys processing" example. `supplier` is auto-assigned when a user becomes a company owner. Only `buyer`/`supplier` are wired to real behaviour — see 0.7b. Plan: `docs/superpowers/plans/2026-08-28-rbac-account-roles.md`. | — | 0 (done) |
| 0.7b | **Feature-gate the remaining 5 account roles.** `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner` are seeded and assignable but check nothing yet — each depends on an entity/workflow that doesn't exist (Processor directory §4.2, Carbon Project §6, Logistics/telematics §7). Wire each role's gate in the same commit as the feature it's meant to gate. | Blocks nothing currently sold; these roles are informational only until their features exist. | TBD — one sub-item per feature as each is built |
| 0.8 | **Certificate — Digital Core.** Rings 1+2 of the product owner's 16-layer certificate security spec (`docs/CERTIFICATE_SPEC.md`): canonical certificate record + versioning, SHA-256 hash, an application-level signing service (real asymmetric signing, explicitly documented as pre-KMS/HSM — not a fabricated hardware integration), status/revocation registry, evidence-manifest hash, geospatial GeoJSON hash + validation, a quantity-allocation ledger (prevents the same certificate being over-claimed across shipments), immutable audit trail, dynamic QR + public verification page, and a certificate PDF using the existing hand-styled-Tailwind house pattern (no fabricated typography plugin). Greenfield — no existing certificate model/table/service found anywhere in the codebase. | Gives every future certified-timber flow (exporter badges, carbon credits, EUDR evidence) one real, cryptographically sound issuance/verification primitive instead of each building its own. | 10 |
| 0.8b | **Certificate — Physical Production Layer.** Ring 3 of the same spec: guilloche/microtext/variable-watermark print-security generation, physical certificate serial + copy registry, print-batch tracking, hologram/UV/tamper-seal integration, supplier/inventory registries. Blocked on a real business decision — which physical tier (Standard/Enhanced/High-Assurance) TimberHub funds, and which print/security-label suppliers it actually engages — not an engineering unknown. Building this against imagined supplier APIs and imagined print-test results would fabricate infrastructure that doesn't exist, exactly what this project's rules exist to prevent. | Deferred by design; see `docs/CERTIFICATE_SPEC.md` "Scope decision". | TBD — depends on chosen tier/supplier |
| 0.8c | ✅ **Certificate allocation carry-over across versions — done.** KMS/HSM signing upgrade remains open. Two known limitations from 0.8's Digital Core, both stated plainly in the code rather than left implicit. (1) ✅ DONE: `certificate_allocations` rows still reference a specific certificate *version* row, but `CertificateAllocationService::remaining()` now sums allocations across every row sharing the same `certificate_number` (the whole version chain), not just the current row's `certificate_id` — additive, no schema/migration change. **Decided:** a re-issued version inherits its predecessor's claimed quantity rather than starting clean, because a version is the same underlying commercial claim. See `database/migrations/2026_08_29_100001_create_certificate_allocations_table.php`'s docblock and `tests/Feature/CertificateAllocationCarryOverTest.php`. (2) STILL OPEN: `CertificateSigningService` signs with an application-level Ed25519 key held in a file on the application server: genuinely separated from the database (an admin browsing Filament cannot read it) but not from the application server itself. A real KMS/HSM integration — where the application asks a signing service to sign and never holds the private key — is the documented next step. | Neither is a fabricated gap; both are consequences of 0.8 shipping a real digital core without pretending to hardware-backed key custody or a settled versioning policy. | TBD — depends on a KMS provider decision |
| 0.9 | **Pricing page (display).** Rebuilt `/pricing` as a segmented, informational catalogue page covering the full 8-segment commercial spec (`docs/PRICING_SPEC.md` — buy/sell/deal/export/international-buy/verify-comply/analyze/learn), reusing the existing live `Plan` DB model for the segment it already genuinely covers (domestic supplier tiers) and presenting every other segment (buyer, dealer, exporter, verification, compliance, traceability, market intelligence, education, promotion, transaction fees, logistics, enterprise) as static informational content with real "from" pricing from the catalogue. Deliberately does NOT wire checkout, billing, subscriptions for new segments, entitlement enforcement, tax calculation, invoicing, or commission logic — see 0.9b. | The commercial spec's own §24 requires "every plan card shows price... no 'Contact sales' as the only price info" — the page had to exist and be accurate before anything could be sold against it. | 2 |
| 0.9b | **Commercial/billing engine.** The rest of `docs/PRICING_SPEC.md`: a multi-segment plan/entitlement data model (buyer/dealer/exporter/verification/compliance/traceability/data/education plans, not just supplier), an entitlement engine consumed identically by web/PWA/API (§22), marketplace commission calculation with caps (§15), a tax engine (§20), invoicing/refunds/credit-notes with immutable historical invoices (§21), pricing governance/versioning/audit (§23), and real payment-provider integration. **Decided:** product owner confirmed no live payment gateway for now — manual/admin-activated plan assignment (matching the existing `SubscriptionService::assign()` flow) is the billing model until a provider decision is made. | Real subscriptions/checkout/entitlements for every segment beyond the existing supplier plans depend on this. | TBD — needs a payment-provider decision first |
| 0.9c | ✅ **Entitlement enforcement — done.** `Company::hasFeature()` already reads real plan data but is only ever used for a read-only admin badge — nothing in the platform actually respects it. Wires `max_gallery` (enforced at the `CompanyGallery` model layer, not just the Filament form), `leads_receive` (enforced in `RfqTriageService::route()` — a company whose plan excludes lead delivery is skipped), and `featured` (surfaced as an admin warning, not a hard block, preserving curatorial discretion) into real behaviour. Deliberately does NOT touch `verified_badge` — `docs/PRICING_SPEC.md` §2 states subscription and verification status are separate concepts; wiring plan payment to verification badges would contradict the spec's own stated principle. Plan: `docs/superpowers/plans/2026-08-28-entitlement-enforcement.md`. | The manual/admin-activated billing model (0.9b's decision) means assigning a plan is the entire "purchase" — if nothing enforces what that plan grants, the plan system is decorative. | 0 (done) |
| 0.9d | **`api` plan entitlement has no surface to enforce yet.** The Enterprise supplier plan's `api` feature key has no company-scoped, authenticated business API to gate today — the existing `api/v1` routes are public/buyer-facing, not company-authenticated. Recorded honestly as unenforceable rather than gated against a surface that doesn't exist. | Blocks nothing currently sold; the Enterprise plan's `api` feature is presently informational only. | TBD — depends on a company-facing API existing first |

**Phase 0 total: ~50.5 days remaining** (0.1 and 0.3 done at 0 days each; 0.2 done at 0 days with its follow-up split out as 0.2b at 6 days — the same 6-day estimate the original 0.2 carried, so no net change there; 0.1b split out at 8 days, +3 over 0.1's original estimate; 0.4 done at 0 days with its follow-ups split out as 0.4b + 0.4c at 1.5 days total, -1.5 days under 0.4's original 3-day estimate; 0.8 added at 10 days, 0.8b unestimated pending a supplier/tier decision). Do not compress this. Every day skipped here is repaid with
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
| 1.5.1 | ✅ **DONE** — additive `Category` tree (self-referencing `parent_id`, `kind` in {form,sector}) seeded with the 6 form groups (raw/secondary-processed/finished/construction/residue/equipment) and 6 sector collections (Hospitality/Education/Healthcare/Office/Residential/Interior Design), all top-level. New nullable `products.category_id` FK + `Product::category()`. `product_type`/`ProductType` and every existing consumer left untouched. `App\Support\CategoryMigrationMap` + idempotent `products:backfill-categories` command backfilled 9/9 categorizable dev products (0 left uncategorized). Plan: `docs/superpowers/plans/2026-08-29-category-tree.md`. Commits `e5b3cbb`, `511afed`, `7df011b`, `2687194`. | 7 |
| 1.5.2 | **Buy Cameroon Wood** experience — domestic search that never requires export vocabulary, domestic filters, delivery zones. | 8 |
| 1.5.3 | **Transformation Network** — processor/manufacturer directory, separate from timber suppliers; "Find a Processor / Manufacturer" entry points. | 8 |
| 1.5.4 | ✅ **DONE** — `Capacity` model `{capability, quantity, unit, period}` (polymorphic `owner`), `HasCapacities` trait wired to `Company`, and `Capacity::scopeMatching()` proven end-to-end (brief §4.3). Commits `620c232`, `aa91b65`, `499e4bc`. Does **not** cover 1.5.2 (domestic search UI) or 1.5.3 (Transformation Network directory) — those remain open. | 4 |
| 1.5.5 | **Manufacturing RFQ + Local Procurement Hub + Project RFQ** (multi-line) over the `rfqs.type` work from 1.5. | 8 |
| 1.5.6 | ✅ **DONE** — `Inventory` model `{product_id, location, quantity_available, unit}` (CHECK quantity_available >= 0), `InventoryService::reserve()`/`restock()` atomic row-locked (mirrors `CertificateAllocationService`), wired as an additive, opt-in call into `OrderService::createFromQuote()` — a line item with no matching `Inventory` row is a silent no-op, and an insufficient-quantity reservation is logged, not blocking, order creation. Commits `5950242`, `0b29935`, `f3d045b`. | 4 |
| 1.5.7 | **Made in Cameroon** badge, qualifying rules, filter, landing page; product QR shows transformation history. | 5 |
| 1.5.8 | **Artisan / professional profiles** with portfolio. | 6 |
| 1.5.9 | **Logistics directory + verification** (trusted / tech-enabled tiers) over the Phase 0 verification framework. | 6 |
| 1.5.10 | **Transport RFQ + booking → `Shipment`**, digital waybill with QR linking cargo product IDs. | 7 |
| 1.5.11 | **Manual checkpoint tracking + public tracking page** (token link, photo + GPS at update). No telematics yet. | 5 |
| 1.5.12 | **Fleet & driver registry** with document expiries feeding alerts. | 5 |
| 1.5.13 | ✅ **DONE** — 8 real articles for brief §4.10's content hub list (why-buy-legal-cameroon-wood, from-forest-to-furniture, meet-the-maker-series, know-your-wood-cameroon, how-to-verify-your-furniture-cameroon, local-vs-imported-timber-cameroon, choosing-timber-construction-furniture-cameroon, sustainable-buying-guide-cameroon), 846–1,323 words each, no fabricated facts/names/stats. New `KnowledgeHub::Domestic` case added additively. Commit `05140de`. | 0 (done) |

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
