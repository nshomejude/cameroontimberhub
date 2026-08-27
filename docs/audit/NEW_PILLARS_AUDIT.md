# New Pillars Audit — Brief Sections 4, 5, 6, 7, 8

Current-state audit of `CTH_Claude_Code_Build_Brief.md` §4–§8 against the codebase at
`.claude/worktrees/company-inquiries-admin-exporter` (branch `worktree-company-inquiries-admin-exporter`).

Sections 3 and 9 are audited separately by another agent and are out of scope here.

**Method.** Read `routes/web.php`, `routes/api.php`, the full contents of `app/Models/`,
`app/Enums/`, `app/Services/`, `app/Filament/`, `config/`, `database/migrations/` (71 migrations,
full list read), `database/seeders/`, and targeted greps. Route names were read from
`routes/web.php` and `routes/api.php` directly (the test/dev DB is shared, so
`php artisan route:list` was not run against it; the route files are the authoritative source and
were read in full).

**Headline.** §4–§8 are essentially unbuilt. Of ~60 brief items audited, **0 are BUILT**,
**8 are PARTIAL**, **1 is STUB**, and the remainder are **MISSING**. What exists is a strong
*export-timber trade* platform (companies, documents, verification, RFQ→quote→order→receipt,
chat commerce, Filament admin, Knowledge Centre) that the new pillars should extend rather than
duplicate.

**Greps used to establish absence** (over `app/`, `database/migrations/`, `routes/`, `config/`,
`resources/views/`, case-insensitive): `carbon`, `sponsor`, `tranche`, `repayment`, `traccar`,
`owntracks`, `opengts`, `telematics`, `geofence`, `vehicle`, `driver`, `shipment`, `logistics`,
`warehouse`, `residue`, `artisan`, `equipment`, `inventory`, `capacity`, `insurance`, `geojson`,
`retirement`, `consent`, `feature_flag`, `price_index`, `market_price`, `AnalyticsEvent`.
Every `carbon` hit is `Carbon\CarbonImmutable` (date library). Every `shipment` hit is the
free-text supplier-typed shipment note on `orders`. Every `logistics` hit is either
`SupplierType::LogisticsProvider` (a directory label only) or the Logistics knowledge hub
(editorial content).

---

## §4 — Domestic market & local value-addition

| Brief item | Status | Evidence / where searched | Reusable existing foundation |
|---|---|---|---|
| 4.1 Cameroon Wood Marketplace ("Buy Cameroon Wood") — domestic-facing storefront | MISSING | Only one marketplace exists, export-framed: `routes/web.php:45-46` → `app/Http/Controllers/Public/ProductController.php`, `app/Services/ProductCatalogueService.php`. No domestic/export split, no XAF-first domestic UX, no natural-language "30 Iroko doors" search. | `ProductCatalogueService` (facets: type, species, region), `app/Livewire/` product listing, `products.price_currency` already defaults `XAF` (`database/migrations/2026_06_25_100010_create_products_table.php`). |
| 4.1 Category taxonomy (raw / secondary processed / finished / construction / sector collections) | PARTIAL | `app/Enums/ProductType.php` is a **flat 16-case enum** of raw+lightly-processed forms (SawnTimber, Logs, Veneer, Flooring, Decking, Mouldings, Plywood, Beams, Planks, Boules, Squares, Sleepers, Poles, Slabs, LaminatedPanels, Charcoal), enforced by a Postgres CHECK constraint (`database/migrations/2026_06_26_100020_expand_products_product_type_check.php`). No `categories` table anywhere in the 71 migrations. **Zero coverage** of finished goods (tables, chairs, beds, cabinets, kitchens, doors, windows, furniture ranges), architectural components (staircases, ceilings, wall panels), outdoor (pergolas, fencing), or sector collections (Hospitality/Education/Healthcare/Office/Residential/Interior). Roughly the "raw/primary" branch plus 4 of the "secondary processed" leaves. | The enum + CHECK-constraint + facet pattern is the thing to *replace* with a `categories` tree table (Section 10 `Category`). `TimberCategory`/`TimberForm` enums show the existing classification idiom; `products.product_type` is the single column to migrate. |
| 4.1 Domestic filters (availability, location/region, delivery) | PARTIAL | Species/type/region/grade filters exist in `app/Services/ProductCatalogueService.php:100-165`. Availability and delivery-zone filters do not exist (no inventory table). | Same service; add facets. |
| 4.1 B2B intermediate trade (sawmill → furniture maker) | MISSING | Buyer role is a distinct persona (`app/Services/BuyerApiScope.php`, `routes/web.php:194` `account.*` group is `middleware('buyer')`). No multi-role account, so a supplier cannot buy. | `company_user` pivot + `app/Enums/CompanyUserRole.php`; spatie/laravel-permission roles (`database/seeders/RolesAndPermissionsSeeder.php`). |
| 4.2 Transformation Network / Find a Processor / Find a Manufacturer | PARTIAL | `app/Enums/SupplierType.php` has exactly 5 cases: `manufacturer`, `exporter`, `trader`, `service_provider`, `logistics_provider` — stored on `companies.supplier_type` with a CHECK constraint (`database/migrations/2026_06_26_100030_add_supplier_metrics_to_companies_table.php`) and filterable in `app/Livewire/CompanyDirectory.php:175`. There is **no** processor, artisan, retailer/distributor, carbon-developer, financier or training-provider type, no capability taxonomy (sawing/kiln drying/planing/CNC/joinery…), no separate directory, and no "Find a Transformer" flow. Homepage (`app/Http/Controllers/Public/HomeController.php`) has one entry point, not three. | `Company` + `companies.supplier_type` is the right column to extend toward Section 10's `Organisation.type`; `app/Livewire/CompanyDirectory.php` is a working faceted directory to clone/parameterise. |
| 4.3 Capacity discovery (`{capability, quantity, unit, period}`, searchable) | PARTIAL | Only two scalar columns: `companies.annual_capacity_m3` and `companies.annual_harvest_capacity_m3` (`2026_06_22_100120`, `2026_08_18_100010`), surfaced read-only on the profile (`app/Http/Resources/Api/V1/SupplierDetailResource.php:26`, `app/Filament/Resources/Companies/Schemas/CompanyForm.php:37,95`). No structured capability rows, no period, no "who can produce 5,000 chairs/month?" query. | Those two columns + the Filament company form are where a `capacities` table (Section 10 `Capacity`) attaches. |
| 4.4 Manufacturing RFQ | MISSING | `rfqs` has **no `type` column** — see `database/migrations/2026_06_22_120010_create_rfqs_table.php` (full column list read): it is hard-wired to export timber (`incoterm`, `shipping_port`, `destination_country_code`, currency CHECK). `rfq_items` (`2026_06_22_120020`) carries species/grade/volume only. No design/drawing upload, finish, installation or quality fields. | **The RFQ engine is the single biggest reusable asset**: `Rfq`/`RfqItem`/`RfqCompany` models, `app/Services/RfqWizard.php` (multi-step wizard), `RfqTriageService`, `RfqRiskService`, `RfqReferenceGenerator`, `AntiSpamService`, `QuoteService`, quote comparison + counter-offers. Add `rfqs.type` + polymorphic requirement payloads rather than building a second engine. |
| 4.4 Local Procurement Hub (institutional requirements, public-procurement flag) | MISSING | No institutional buyer concept; `rfqs.visibility` CHECK allows only `public|private|admin_assisted`. Grepped `procurement`, `tender`, `institution` — no hits. | `rfqs.visibility` + `RfqCompany` matching table. |
| 4.4 Project RFQ (multi-line project) | MISSING | `rfqs.project_name` exists (`2026_08_18_090000_add_title_and_project_name_to_rfqs_table.php`) but it is a free-text label, not a project decomposed into RFQ lines. | Same RFQ engine; `rfq_items` already supports multiple lines. |
| 4.5 Made in Cameroon badge, filter, landing page | MISSING | `app/Enums/BadgeType.php` + `config/compliance.php:badge_requirements` define 8 badges (`verified_company`, `verified_exporter`, `sigif_registered`, `legal_timber_supplier`, `export_ready`, `cites_approved`, `sustainability_profile`, `premium_member`) — all **company-level**, none product-level, none "Made in Cameroon". No such route in `routes/web.php`. `products.origin` defaults to `'Cameroon'` but drives nothing. | `VerificationBadge` model + `BadgeService` + `config/compliance.php` badge-requirement map is exactly the right mechanism — extend it to product-scoped badges. |
| 4.5 Product ID / QR with transformation history | PARTIAL | A verifiable-ID pattern **exists but only for receipts**: `receipts.receipt_number` + unguessable `verification_token`, public verification at `routes/web.php:133-137` → `ReceiptVerificationController`, `app/Services/ReceiptVerifier.php`. Products have **no** `product_id`/QR/public verification page and no processing-history record. | This is the highest-value pattern to copy: number + separate unguessable token + public verify page + verification counter + void semantics (`database/migrations/2026_08_18_120030_create_receipts_table.php`). |
| 4.6 Wood professionals & artisans (maker profiles, portfolio, directory) | MISSING | No `artisan`/`professional` model, enum case, table or route. Nearest analogue is `CompanyGallery` (`database/migrations/2026_06_22_100150`) for company images. | `Company` + `CompanyGallery` (portfolio images) + `CompanyReview` (reviews/ratings) + `CompanyDirectory` Livewire component. |
| 4.7 Equipment marketplace (new/used/lease) | MISSING | Grep `equipment` → no model, table, route or enum. | `Product`/`ProductImage` + `ProductCatalogueService` faceting, or a sibling listing table. |
| 4.8 Finance & investment directory + introduction requests | MISSING | Grep `finance`/`financier`/`leasing` → only the `finance_officer` **role** in `database/seeders/RolesAndPermissionsSeeder.php`. No directory, no request form. | `Company` (type extension) + `CompanyInquiry`/`InquiryTriageService` as the "request introduction" flow. |
| 4.9 Wood Residue Exchange | MISSING | Grep `residue`/`sawdust`/`offcut`/`briquette` → single hit: `app/Enums/ProductType.php:73` describes `Charcoal` as made from residues. No listing type, no recurring-availability model. | `ProductType::Charcoal` + `Product` model; the residue exchange can be a category branch + listing subtype rather than a new marketplace. |
| 4.10 Species guide | PARTIAL (strongest item in §4) | `species` table with knowledge fields — `french_name`, `taxonomy`, `characteristics`, `workability`, `drying_behaviour`, `treatments`, `grades_available`, `log_export_status`, `eudr_risk_note`, `region_availability`, `authoritative_sources`, commercial classification (`2026_06_22_100110`, `2026_06_26_100010`, `2026_08_27_100010`). Public pages `routes/web.php:61-62` → `SpeciesController` + `app/Services/SpeciesDirectoryService.php`; admin CRUD `app/Filament/Resources/Species`. **Missing vs §4.10:** price indicators, linked processors, linked products, "Discover Cameroon Wood" lesser-known-species promotion. | Use as-is; add relations + a price-indicator field. Do not rebuild. |
| 4.10 CMS content hub (Why Buy Legal Cameroon Wood, Meet the Maker, …) | PARTIAL | Knowledge Centre exists and is substantial: `app/Enums/KnowledgeHub.php` (11 hubs), `routes/web.php:66-92` (`/knowledge`, `/knowledge/{hub}`, `/knowledge/{hub}/{slug}`, `/insights`, glossary), `Article`/`GlossaryTerm`/`Page` models, `app/Console/Commands/ImportArticles.php`, `app/Filament/Resources/Articles`, sitemap + `llms.txt` (`SitemapController`). Hubs are export/trade-framed; none of the eight §4.10 domestic titles exist as content, and there is no maker/product cross-linking. | **§4.10's content-hub requirement is largely satisfied structurally** — it needs domestic articles and a hub (or hub-tag), not new machinery. `KnowledgeHub` enum + `ImportArticles` is the authoring pipeline. |
| 4.10 Training, courses, apprenticeship matching (Phase 3) | MISSING | Grep `course`/`apprentice`/`training` → no hits outside brief text. | None. |
| 4.11 Retailer/distributor role & directory | MISSING | Not in `SupplierType` (5 cases, listed above). | `companies.supplier_type` CHECK constraint. |
| 4.11 Domestic logistics (pickup/trucking/dispatch/delivery tracking) | MISSING | See §7 below. | — |
| 4.11 Warehouse listings | MISSING | Grep `warehouse` → no hits in `app/`, `database/`, `routes/`. | — |
| 4.12 Inventory visibility (live stock, "Available now", decrement on order) | MISSING | No `inventory`/`stock` table or column; `products` (full column list read) has price/MOQ/dimensions but no quantity-on-hand. `OrderService`/`OrderLifecycleService` never decrement anything. | `Product` + `OrderLifecycleService` hooks; `orders`/`order_items` already model quantities. |
| 4.13 Growth pathway levels (ARTISAN → GLOBAL SUPPLIER) | MISSING | Grep `growth`/`pathway`/`level`/`tier` on companies → nothing. `CompanyCompletenessService` scores *profile completeness* (0–100, `companies.profile_completion`), which is not the same concept. | `CompanyCompletenessService` + `CompletenessResult` + `VerificationBadge` + trust metrics (`orders_completed`, `rating_avg`, `on_time_delivery_percent`) supply nearly all the criteria inputs a level engine needs. |

**§4 counts — BUILT 0 · PARTIAL 6 · STUB 0 · MISSING 15**

---

## §5 — Carbon & Climate Finance

Nothing in this section exists in any form. Grep for `carbon`, `credit`, `retirement`, `vintage`,
`methodology`, `REDD`, `MRV`, `benefit`, `geojson`, `boundary`, `hectare`, `offset`, `biodiversity`,
`SDG` across `app/`, `database/migrations/`, `routes/`, `config/`, `resources/views/` returns only
`Carbon\CarbonImmutable`/`Illuminate\Support\Carbon` date-library imports
(`app/Console/Commands/ImportArticles.php:12`, `app/Jobs/SendDocumentExpiryReminderJob.php:9`,
`app/Models/Order.php:14`, `app/Services/BuyerDashboard.php:15`) and the `sustainability`
Knowledge Centre hub (`app/Enums/KnowledgeHub.php`), which is editorial content only.

| Brief item | Status | Evidence / where searched | Reusable existing foundation |
|---|---|---|---|
| 5.1 Carbon Project Registry (profile, GeoJSON boundary, 11 project types, status machine) | MISSING | No `carbon_projects` table in any of the 71 migrations; no model, controller, route or Filament resource. | `Company` (developer org), `CompanyDocument`+`DocumentType` (project documents), `VerificationRequest` state pattern, `app/Filament/Resources/*` CRUD scaffolding. **No geospatial storage of any kind exists** — `companies.latitude/longitude` (decimal 9,6) is the only geo data; no PostGIS, no GeoJSON column. |
| 5.2 Carbon Project Digital ID (`CTH-CARB-CMR-000021`) + QR | MISSING | Grep `CTH-CARB` → no hits. | `OrderReferenceGenerator`, `RfqReferenceGenerator`, `QuoteReferenceGenerator` (`app/Services/`) are the reference-code idiom; `receipts` token + `ReceiptVerifier` + `/verify/{token}` is the public-verification idiom. |
| 5.3 Credit lifecycle (batches, serial ranges, separate issued/available/sold/retired counters) | MISSING | No credit/batch/serial model. | — |
| 5.4 Carbon discovery & procurement (filters, data room, purchase request) | MISSING | No routes; `SearchService` covers products/companies/species only (`app/Services/SearchService.php`). | `SearchService`, `ProductCatalogueService` faceting, `CompanyDocument.visibility` + `DocumentAccessLog` (a real data-room access-log primitive already exists). |
| 5.5 Retirement certificates (publicly verifiable) | MISSING | Grep `retirement` → no hits. | **Direct analogue exists**: `Receipt` + `verification_token` + `/verify/{token}` + `ReceiptVerifier` + void semantics. Retirement certificates should reuse this exact pattern. |
| 5.6 Benefit-sharing ledger | MISSING | No ledger table anywhere in the schema. | — (see §6.10; both need the same double-entry-ish ledger primitive). |
| 5.7 Sponsorship marketplace ("sponsor a hectare") | MISSING | Grep `sponsor` → no hits. | — |
| 5.8 Monitoring & MRV (satellite/GIS/drone/field) | MISSING | No integration, job or config. | Queue/jobs infra (`app/Jobs/`, `config/queue.php`, Redis via predis). |
| 5.9 Calculators (all outputs "estimate") | MISSING | Grep `calculator` → no hits. | — |
| 5.10 Green manufacturer profile indicators | MISSING | Company profile has no environmental indicators; `sustainability_profile` badge exists but is backed only by `legality_certificate` (`config/compliance.php`). | `VerificationBadge` + `config/compliance.php:badge_requirements`. |
| 5.11 Timber ↔ carbon linkage on product records | MISSING | `products` has `origin` and `certification` free-text only. | `products.origin`/`certification`, `Species` relation. |
| Governance: estimate-vs-verified distinction, "never the issuer" disclaimers, feature-flagged trading | MISSING | No feature-flag infrastructure exists at all (see Regulatory flags below). | `config/compliance.php`, `config/trust.php` are the config idiom a flag registry would follow. |

**§5 counts — BUILT 0 · PARTIAL 0 · STUB 0 · MISSING 12**

---

## §6 — Forest Sponsorship & Strategic Investment

Nothing exists. Grep for `sponsor`, `tranche`, `disbursement`, `waterfall`, `repayment`,
`scorecard`, `due_diligence`, `committee`, `funding_agreement`, `term_sheet`, `screening_fee`,
`grievance`, `collateral`, `beneficial_owner`, `off_take`/`offtake` across the repo returns **zero
hits** in application code.

| Brief item | Status | Evidence / where searched | Reusable existing foundation |
|---|---|---|---|
| 6.1 New roles (`forest_operator`, `sponsor`, `sponsorship_analyst`, `field_verifier`, `compliance_officer`, `investment_committee_member`, `finance_officer`, `sponsorship_admin`) | PARTIAL | `database/seeders/RolesAndPermissionsSeeder.php` defines `super_admin`, `admin`, `verification_officer`, `content_manager`, `sales_officer`, `support_officer`, `finance_officer` with granular permissions (`verification.review`, `documents.review`, `badges.issue`, `badges.revoke`, `companies.suspend`, `audit.view`, `rfqs.triage`, `rfqs.route`, `users.manage`, …). `finance_officer` exists by name; the other eight sponsorship roles do not. | **spatie/laravel-permission is installed and the permission model is granular and role-mapped** — adding the roles is additive, not architectural. `app/Enums/CompanyUserRole.php` handles org-level roles. |
| 6.2 Operator journey & funding application (identity, beneficial owners, title type, GeoJSON area, permits, budget, cash-flow) | MISSING | No application model. Nearest: `companies.sigif_operator_id`, `companies.sigif_permit_numbers` (jsonb), `companies.forest_location`, `companies.forest_management`, `companies.annual_harvest_capacity_m3` — descriptive profile fields, not an application. | Those SIGIF/forest columns + `CompanyDocument`/`DocumentType` (which already models issuer, issue/expiry dates, SHA-256 checksum, review status, reviewer, SIGIF field payloads). Mappable GeoJSON: **nothing exists**. |
| 6.3 Sponsor / strategic buyer journey, KYB, sanctions/adverse-media screening | MISSING | Grep `kyb`, `kyc`, `sanction`, `adverse` → no hits. `AntiSpamService`/`SuspiciousEvent` is inbound-spam scoring, not AML screening. | `Company` + `CompanyDocument` + `VerificationRequest` workflow. |
| 6.4 Project lifecycle state machine (16 states + suspend/terminate) | MISSING | No such state machine. Existing machines: `OrderStatus` (`app/Enums/OrderStatus.php` + `OrderLifecycleService`, guarded transitions), `RfqStatus`, `QuoteStatus`, `VerificationRequestStatus`, `CompanyStatus` — all enforced by Postgres CHECK constraints plus service-layer guards. | `app/Services/OrderLifecycleService.php` is a mature, well-guarded state machine with activity logging — the pattern to copy. |
| 6.5 Due-diligence package (10 checklist areas) & configurable scorecard | MISSING | Grep `scorecard`, `weights`, `due` → nothing. | `VerificationRequest` (assigned_to, decided_by, decided_at, decision_notes, `document_snapshot` jsonb) is a single-step approximation of one DD item; `app/Filament/Resources/VerificationRequests` is a working review queue. `config/compliance.php:badge_requirements` shows the admin-configurable-criteria idiom. |
| 6.6 Tiers & transaction structures, `regulatory_cleared` flag per structure | MISSING | No tier/structure model. `Plan`/`Subscription` are SaaS billing tiers, unrelated. | `Plan` shows the admin-configurable-parameters pattern; nothing for `regulatory_cleared`. |
| 6.7 Funding Agreement, tranche schedule, dual authorisation | MISSING | Grep `dual`, `authoris`, `tranche` → nothing. No approval-chain primitive exists anywhere (all approvals in the codebase are single-actor: `verification_requests.decided_by`, `company_documents.reviewed_by`). | `ContractAcceptance` model (`database/migrations/2026_08_19_140040`) is the only signed-agreement primitive and is quote-scoped. |
| 6.8 Field monitoring, geotagged evidence, volume reconciliation, automated alerts, auto-freeze | MISSING | No geotag/EXIF/GPS handling; `CompanyGallery`/`ProductImage` store plain images. Alerts: only `app/Jobs/SendDocumentExpiryReminderJob.php` + `RemindExpiringDocuments` command + `DocumentReminderLog` (document-expiry only). | **The document-expiry alerting chain (`config/compliance.php:reminder_thresholds`, scheduled command, job, `DocumentReminderLog`) is a real, working alert engine** to generalise into `SponsorshipAlert`. |
| 6.9 Separation of duties enforced in code (originator ≠ approver, compliance blocks, conflicts) | MISSING | No originator/approver distinction anywhere. Policies: `app/Policies/` + ownership checks in controllers are single-actor. | spatie permissions + `spatie/laravel-activitylog` (`activity_log` table, `2026_06_22_090514` + event/batch-uuid columns, `audit.view` permission, `->log(...)` calls throughout `OrderLifecycleService`) give the audit-trail half for free. |
| **6.10 Repayment ledger (cash + wood), waterfall engine** | MISSING | No ledger, no monetary-entry table of any kind. `orders` has `payment_status`/`payment_*` fields (`database/migrations/2026_08_19_150020_add_shipment_and_payment_fields_to_orders_table.php`) recording a single order-level payment state, no entries. `Receipt` explicitly documents itself as **not** a proof of payment ("this platform has no payment integration"). | `Order`/`OrderItem`/`Receipt` (the wood-delivery side), `orders.payment_*` (the cash side). The ledger itself is new. |
| **6.10 Portfolio dashboards (admin "All sponsorships", sponsor, operator, public, statement page)** | MISSING | No route, controller or Filament page. | `app/Services/BuyerDashboard.php` (role-scoped dashboard aggregation, currency-grouped totals — good precedent), `app/Filament/Widgets/`, Filament tables with filters. |
| 6.11 Fees, published tariff, mandatory disclosure acknowledgement with timestamp | MISSING | No fee model. `ContractAcceptance` is the only timestamped acknowledgement primitive in the schema. | `ContractAcceptance` (generalise to typed disclosures); `Plan` for the published-tariff idiom. |
| 6.12 Default, suspension & recovery; grievance/whistleblowing channel | MISSING | Grep `grievance`, `whistle`, `default`, `recovery` → nothing. `CompanyStatusService` supports company suspension only (`companies.suspend` permission). | `CompanyStatusService` + activitylog. |
| 6.13 Document pack (15 generated/uploaded document types) | PARTIAL | The **document store is real and good**: `document_types` (key, required, requires_expiry, affects_verification, supports_sigif, sort order), `company_documents` (SHA-256 checksum, status, visibility, issue/expiry dates, reviewer, rejection reason), `document_access_logs`, signed 5-minute download URLs (`config/compliance.php:signed_url_ttl`, `routes/web.php:95`), `DocumentService`, Filament review resource. It is **company-scoped only** (`company_documents.company_id` FK, not polymorphic) and none of the 15 sponsorship document types are seeded (`database/seeders/DocumentTypeSeeder.php`). PDF *generation* exists only as `order.proformaSheet` (`routes/web.php:259`). | Make `company_documents` polymorphic (`documentable_type/_id`) and seed new `document_types` rows — a small migration unlocks §5, §6 and §7 document needs at once. This is the single most leveraged change in the whole audit. |
| 6.14 Integration (off-take delivery → Order + Receipt + wood repayment entry; proceeds → waterfall) | MISSING | Depends on everything above. | `OrderLifecycleService` transition hooks are the natural insertion point. |

**§6 counts — BUILT 0 · PARTIAL 3 · STUB 0 · MISSING 12**

---

## §7 — Logistics, Tracking & Road Safety

| Brief item | Status | Evidence / where searched | Reusable existing foundation |
|---|---|---|---|
| 7.1 Logistics Company Directory, two tiers (Trusted / Technology-Enabled), logistics documents | STUB | `SupplierType::LogisticsProvider` exists (`app/Enums/SupplierType.php:16`, label "Logistics Provider", truck icon) and is filterable in `app/Livewire/CompanyDirectory.php:175`. That is the entire implementation: **a filter value on the generic company directory**. No tiers, no `Live-tracked` badge, no routes/regions/services/fleet/insurance/pricing-basis fields, no logistics document types in `DocumentTypeSeeder`, no on-time/damage metrics beyond the generic `on_time_delivery_percent` column. | `Company` + `CompanyDirectory` + `VerificationBadge`/`BadgeService` (add `trusted_logistics`, `live_tracked` badge types + `config/compliance.php` requirements) + `document_types` seeding for transport licence, vehicle registration, insurance certificate. |
| 7.2 Transport RFQ & booking; Shipment entity; digital waybill with QR + cargo product IDs | MISSING | `rfqs` has no type discriminator and no origin/destination/cargo/pickup-window columns. **There is no `Shipment` model or table.** What exists is free text on the order: `orders` shipment fields (`2026_08_19_150020`) surfaced as `Order::shipmentFacts()` (`app/Models/Order.php:141-174`), `OrderLifecycleService::ship()/updateTracking()` (`:239-300`), `MessageType::ShipmentUpdate`, and the Filament action at `app/Filament/Exporter/Resources/Orders/Tables/OrdersTable.php:78-115` whose own comment states *"There is no carrier integration behind"* this. | The RFQ engine (as §4.4) for Transport RFQ; `orders.shipment_*` columns are the fields to migrate into a real `Shipment`; `Receipt` token+QR pattern for the waybill. |
| 7.3 Fleet & driver registry (vehicles, expiries, telematics device, driver licences, consent, safety score) | MISSING | Grep `vehicle`, `driver`, `plate`, `fleet`, `licence`/`license`, `imei` → no model, table or column. | `company_documents` expiry + reminder chain is exactly what vehicle/licence expiry alerts need (once polymorphic). `company_user` pivot for driver↔carrier. |
| 7.4 Tracking engine — Traccar REST/WebSocket, OwnTracks, OpenGTS adapter, normalised Position/TrackingEvent stream, geofences, public tracking page, geofenced delivery confirmation | MISSING | Grep `traccar`, `owntracks`, `opengts`, `geofence`, `websocket`, `broadcast`, `position`, `lat.*lng` → nothing. `config/services.php` has no tracking provider. No broadcasting driver configured; no realtime layer of any kind (Redis is present via predis for cache/queue only). | Queue/jobs + Redis for ingestion; the `/verify/{token}` unauthenticated-token-page pattern for the public tracking link; `companies.latitude/longitude` is the only existing geo precedent. **No spatial column type, no PostGIS extension** (only `pg_trgm`, `2026_06_22_100100`). |
| 7.5 Route & safety analytics, route risk map, pre-trip checklist, incident reporting | MISSING | Grep `incident`, `route`, `risk_segment`, `overspeed`, `harsh` → only `app/Services/RfqRiskService.php` (RFQ spam/quality risk, unrelated). | — |
| 7.6 Insurance telematics partnership, consent records, data minimisation, scoped insurer API keys | MISSING | Grep `insurance` → `RfqIncoterm::CIF` label and `OrderDocumentKind` comment only. Grep `consent` → an unstored RFQ checkbox (`app/Services/RfqWizard.php:112,236`, `resources/views/public/rfq/steps/contact.blade.php:44-47`) validated as `accepted` but **never persisted**. There is no consent record table, no revocation, no scope. | `laravel/sanctum` (installed, `personal_access_tokens` table `2026_08_22_185421`) can back scoped insurer API keys; `DocumentAccessLog` is the "audit every data access" precedent. |
| 7.7 Driver reports (trip/driver/fleet/shipper/admin), safety score config, event disputes, review workflow, access matrix | MISSING | No source events exist to report on. | `app/Services/BuyerDashboard.php` role-scoped aggregation; Filament widgets. |
| 7.8 Integration points (orders, sponsorship deliveries, residues, warehousing, provenance transport leg, reputation) | MISSING | Depends on all of the above. | `OrderLifecycleService` hooks; `CompanyReviewService` for reputation. |

**§7 counts — BUILT 0 · PARTIAL 0 · STUB 1 · MISSING 7**

---

## §8 — Intelligence layer

| Brief item | Status | Evidence / where searched | Reusable existing foundation |
|---|---|---|---|
| Cameroon Timber Market Insights (indicative prices by species/grade/region, demand, lead times, inventory, export trends) | MISSING | **Name collision warning:** `/insights` (`routes/web.php:82-85`, `InsightController`, `Article` model) is the *editorial blog*, not market data. No price series, no `price_index`/`market_price` column, no demand or lead-time aggregation. Live aggregation that does exist is only count-by-facet for directory filters (`app/Services/ProductCatalogueService.php:110-159`, `app/Services/SpeciesDirectoryService.php:232-261`, `app/Livewire/CompanyDirectory.php:175-272`). | `products.price_amount/price_currency/price_unit`, `quotes`/`quote_items`, `orders`/`order_items` and `rfq_items` already hold every input a price/demand index needs — the data is there, the aggregation job is not. |
| Cameroon Wood Economy dashboard (public KPIs) | MISSING | No dashboard route or Filament page beyond `app/Filament/Widgets/` admin widgets. | `BuyerDashboard` aggregation patterns; Filament widgets; `activity_log` as an event source. |
| Carbon Market Intelligence | MISSING | Depends on §5, which does not exist. | — |
| Event logging + nightly aggregation job (the brief's stated starting point) | PARTIAL | `spatie/laravel-activitylog` is installed and used throughout (`activity_log` table with `event` and `batch_uuid` columns), giving a real event stream. But there is **no `analytics_events` table, no aggregation/snapshot table, and no scheduled aggregation job** — `routes/console.php` schedules only two compliance jobs (`compliance:expire-badges` 06:30, `compliance:remind-expiring` 07:00). | `activity_log` + the existing scheduled-command + job + log-table triad (`RemindExpiringDocuments` → `SendDocumentExpiryReminderJob` → `DocumentReminderLog`) is a working template for a nightly aggregation job. |

**§8 counts — BUILT 0 · PARTIAL 1 · STUB 0 · MISSING 3**

---

## Totals

| Section | BUILT | PARTIAL | STUB | MISSING |
|---|---|---|---|---|
| §4 Domestic market | 0 | 6 | 0 | 15 |
| §5 Carbon & climate | 0 | 0 | 0 | 12 |
| §6 Sponsorship | 0 | 3 | 0 | 12 |
| §7 Logistics & tracking | 0 | 0 | 1 | 7 |
| §8 Intelligence | 0 | 1 | 0 | 3 |
| **Total** | **0** | **10** | **1** | **49** |

---

## 1. Existing foundations worth building on

1. **The RFQ → Quote → Order → Receipt engine.** `Rfq`/`RfqItem`/`RfqCompany`, `RfqWizard`
   (multi-step, resumable), `RfqTriageService`, `RfqRiskService`, `AntiSpamService`, `QuoteService`,
   `QuoteCounterOffer` (revision chains), `ContractAcceptance`, `OrderService`,
   `OrderLifecycleService` (guarded state machine with activity logging), `OrderDocumentService`,
   `Receipt`. Manufacturing RFQ, Processing RFQ, Project RFQ and Transport RFQ should all be
   `rfqs.type` discriminators over this engine plus type-specific requirement payloads — never a
   second engine.
2. **The receipt verification pattern** (`receipts.receipt_number` + separate unguessable
   `verification_token`, `/verify/{token}`, `ReceiptVerifier`, verification counter, void-but-still-
   verifies semantics). This is the exact shape needed for: product IDs/QR (§4.5), carbon project
   IDs (§5.2), retirement certificates (§5.5), sponsorship project IDs (§6.8), and the public
   shipment tracking link (§7.4). Copy it five times rather than inventing five schemes.
3. **The document store and its expiry machinery.** `document_types` (configurable requirement
   metadata) + `company_documents` (SHA-256 checksum, status, visibility, issue/expiry, reviewer,
   rejection reason) + `DocumentAccessLog` + signed short-TTL downloads + `RemindExpiringDocuments`
   → `SendDocumentExpiryReminderJob` → `DocumentReminderLog`. Making `company_documents`
   polymorphic is the single highest-leverage migration in this audit: it unlocks carbon project
   documents, the sponsorship 15-document pack, and vehicle/driver/insurance expiries at once, and
   the reminder chain generalises directly into `SponsorshipAlert`.
4. **The verification framework and badge system.** `VerificationRequest` (assign/decide/notes/
   document snapshot) + Filament review queue + `VerificationBadge` + `BadgeService` +
   `config/compliance.php:badge_requirements`. Company-scoped today; the badge-requirements config
   map is precisely the mechanism for "Made in Cameroon", "Trusted Logistics", "Live-tracked" and
   "Green Manufacturer".
5. **Filament v5 admin.** 16 admin resources plus a separate Exporter panel, with tables, filters,
   schemas and actions already conventionalised (`app/Filament/Resources/*`,
   `app/Filament/Exporter/*`). Every new pillar's back-office is scaffolding work, not design work.
6. **RBAC + audit log.** spatie/laravel-permission with granular permissions and seven seeded roles
   (including `finance_officer`), plus spatie/laravel-activitylog wired into the service layer and
   an `audit.view` permission with a Filament ActivityLog resource. §6.9's separation of duties has
   its audit half already.
7. **The Company aggregate and directory.** `companies` already carries verification state, trust
   metrics (`rating_avg`, `orders_completed`, `response_time_hours`, `on_time_delivery_percent`),
   SIGIF operator/permit identifiers, forest location/management, capacity, lat/lng, and
   `supplier_type` behind a CHECK constraint. `app/Livewire/CompanyDirectory.php` is a working
   faceted directory. `supplier_type` is the column to widen toward Section 10's
   `Organisation.type`; the directory component is the thing to parameterise for the Transformation
   Network, artisan, logistics, financier and carbon-developer directories.
8. **The Knowledge Centre + species catalogue.** 11 hubs, articles with an import pipeline,
   glossary, SEO/sitemap/`llms.txt`, and a species table already carrying workability, drying
   behaviour, treatments, grades, EUDR risk notes and regional availability. §4.10's content hub is
   structurally satisfied; it needs domestic content and product/processor cross-links, not new
   machinery.
9. **`CompanyCompletenessService` + trust metrics** as the input layer for §4.13's growth pathway.
10. **Queue/Redis, Sanctum, and the API v1 resource layer** (`routes/api.php`,
    `app/Http/Resources/Api/V1/*`) for tracking ingestion and scoped insurer/partner API access.

## 2. Genuinely new subsystems (no existing analogue)

- **Geospatial storage and mapping.** The database has `pg_trgm` only; the sole geo data is
  `companies.latitude/longitude`. There is no GeoJSON column, no PostGIS, no map component, no
  boundary/polygon concept, no geotagged-upload handling. §5.1 (project boundaries), §6.2
  (operational areas), §6.8 (geotagged evidence), §7.4 (geofences, positions) all need this from zero.
- **A financial ledger and waterfall engine.** No monetary entry table exists; `Receipt` explicitly
  states the platform has no payment integration. §6.10's cash+wood repayment ledger, dual-typed
  entries, valuation formulas and configurable waterfall are entirely new — and are the reporting
  core the brief calls out as mandatory.
- **Real-time telemetry ingestion.** No broadcasting driver, no WebSocket layer, no external
  device/protocol integration anywhere in the codebase. Traccar/OwnTracks/OpenGTS normalisation,
  position storage at volume, downsampling/retention, and live map publication are new end to end.
- **Multi-actor approval workflows.** Every approval in the codebase is single-actor
  (`decided_by`, `reviewed_by`). Dual authorisation by amount band, originator≠approver,
  committee decisions with declared conflicts, and compliance veto have no primitive to extend.
- **Inventory / stock accounting.** Nothing decrements anywhere; §4.12 and §6.8 volume
  reconciliation both need a movement-based stock model.
- **A category tree.** `ProductType` is a flat enum behind a CHECK constraint; a hierarchical,
  admin-manageable taxonomy with sector collections is a different data structure.
- **Time-series analytics.** No analytics-event or snapshot table, no aggregation job, no charting.
- **Person-level (non-company) profiles.** Every actor today is a `Company` or a `User` attached to
  one. Artisans, professionals and drivers are individuals with portfolios, skills, consents and
  scores — a new profile aggregate.
- **Document generation at scale.** Only one PDF-ish artefact exists (the proforma sheet). Term
  sheets, settlement statements, closure certificates, trip reports and driver reports need a real
  generation pipeline.

## 3. Regulatory / governance flags

The brief gates several capabilities on legal sign-off. **The codebase currently has none of the
three infrastructure primitives those gates require.**

- **No feature-flag infrastructure.** Grep for `feature_flag`, `features.`, `Feature::`,
  `pennant` → zero hits; `composer.json` has no flags package. `config/compliance.php` and
  `config/trust.php` hold static config, not per-tenant or per-structure toggles. The brief
  requires flags for: carbon *trading* until registry/legal requirements are met (§5 governance
  rule), each sponsorship transaction structure's `regulatory_cleared` state (§6.6 — COSUMAF/CEMAC
  rules on public offers and private placements), and the insurer telematics feed until
  data-protection and CIMA reviews are signed off (§7.6). **A flag registry with per-structure and
  per-jurisdiction scoping should be built before any of these pillars ships**, not retrofitted —
  `regulatory_cleared` in particular must be enforced at the query/authorisation layer so uncleared
  structures cannot be *offered*, not merely hidden in the UI.
- **No consent infrastructure.** The only consent in the codebase is an RFQ checkbox validated as
  `accepted` (`app/Services/RfqWizard.php:112,236`) and then **discarded** — it is not persisted,
  has no scope, no timestamp, no version, and no revocation path. §7.6 requires explicit, informed,
  **revocable** consent recorded per driver, per vehicle, per insurer and per scope, displayed in
  the subject's profile, with revocation stopping the feed; §6.11 requires the mandatory
  no-guarantee disclosure acknowledged and **stored with a timestamp** before any fee is taken.
  `ContractAcceptance` (`database/migrations/2026_08_19_140040`) is the nearest primitive and is
  quote-scoped; it should be generalised into a typed, versioned, revocable consent/acknowledgement
  record. Cameroon's personal-data law and cross-border transfer rules apply to any telematics
  feed and are unaddressed in code.
- **Audit log exists but is not tamper-evident and not separation-of-duties aware.**
  `spatie/laravel-activitylog` (`activity_log`, with `event` and `batch_uuid`) plus an `audit.view`
  permission is a genuine foundation and is already called from `OrderLifecycleService` and the
  Filament actions. However: entries are plain rows (no hash chain — note that even `Receipt`,
  despite storing a checksum idiom elsewhere in `company_documents.checksum_sha256`, has no
  integrity chain, and the brief's `IntegrityRecord` does not exist), there is no immutability
  guarantee, and nothing records *who was excluded* from an action. §6.9's "originator ≠ approver",
  declared conflicts on every committee decision, and documented senior approval for exceptions
  need a purpose-built approval/decision record on top of activitylog.
- **No AML / sanctions / adverse-media screening** of any kind (§6.3, §6.5 Integrity & Reputation
  area). `AntiSpamService`/`SuspiciousEvent` is inbound-form spam scoring and must not be mistaken
  for it.
- **No insurer/partner data-access scoping or access audit for telematics.** `DocumentAccessLog`
  is the right precedent (per-access logging) and Sanctum can supply scoped keys, but neither is
  applied to any partner-facing data feed today.
