# Core Trade Platform Audit — Brief Sections 3 & 9

**Scope:** Section 3 (Core trade platform) and Section 9 (Cross-cutting requirements) of
`CTH_Claude_Code_Build_Brief.md`. Sections 4–8 are audited separately.

**Method:** read of `routes/web.php`, `routes/api.php`, all 68 files in `database/migrations/`,
`app/Models/`, `app/Services/`, `app/Policies/`, `app/Enums/`, `app/Filament/`,
`resources/views/`, `tests/Feature/`. `php artisan route:list` reports **178 routes**.
Verdicts are grounded in schema and code, not names.

**Verdict key:** `BUILT` = data model + backend + UI, working end to end ·
`PARTIAL` = exists but materially narrower than the brief · `STUB` = UI or column exists
with no writing backend · `MISSING` = nothing.

---

## 3.1 Accounts & roles / RBAC

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Role-aware onboarding "Join as Buyer / Join as Supplier" from first screen | PARTIAL | `routes/web.php:170-178` (`/register`), `app/Http/Controllers/Auth/RegisterController.php` | One generic registration form. Company/supplier onboarding is a separate admin/exporter-panel path (`app/Filament/Exporter/Pages/OnboardingChecklist.php`), not a first-screen role choice. |
| One account, multiple roles | PARTIAL | `database/migrations/2026_06_22_100130_create_company_user_table.php`; `app/Enums/CompanyUserRole.php` | A user can belong to many companies with owner/manager/member. But there is no *platform* role dimension per membership — "buyer" is defined negatively (`EnsureBuyerAccount` bounces anyone with a company or staff role), so a sawmill that is both supplier and buyer cannot use `/account`. |
| Role `admin` | BUILT | `database/seeders/RolesAndPermissionsSeeder.php:41` | |
| Role `verifier` | PARTIAL | `RolesAndPermissionsSeeder.php:50` | Exists under a different name, `verification_officer`, with `verification.review`/`badges.*`. Rename or alias needed. |
| Role `buyer` | MISSING | — | No spatie role. Buyer-ness is inferred by `app/Http/Middleware/EnsureBuyerAccount.php` (absence of company + absence of staff role). |
| Role `supplier` | MISSING | — | Inferred from `company_user` membership only. |
| Roles `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner` | MISSING | `RolesAndPermissionsSeeder.php:36-60` | Seeded roles are exactly: `super_admin`, `admin`, `verification_officer`, `content_manager`, plus three empty future roles `sales_officer`, `finance_officer`, `support_officer`. None of the five brief roles exists anywhere in the codebase. |
| Organisation type taxonomy (Section 10 `Organisation.type`) | PARTIAL | `app/Enums/SupplierType.php`; `database/migrations/2026_06_26_100030_add_supplier_metrics_to_companies_table.php:19` | `companies.supplier_type` = manufacturer / exporter / trader / service_provider / logistics_provider. Does **not** include buyer, processor, artisan, retailer, carbon_developer, financier, training_provider. Enforced by a Postgres CHECK constraint, so adding types is a migration. |
| Buyer profile: individual/company, procurement profile, markets | MISSING | `database/migrations/0001_01_01_000000_create_users_table.php` | `users` carries name/email/password only. No buyer entity, no individual-vs-company flag, no procurement profile, no markets. Buyer identity is denormalised per-transaction onto `rfqs.buyer_*` and `orders.buyer_*`. |
| Buyer RFQ / order / trade history | BUILT | `app/Services/BuyerDashboard.php`; `routes/web.php:196-201`; `resources/views/public/account/*.blade.php` | Real, scoped, tested (`tests/Feature/BuyerDashboardTest.php`). |

The RBAC story is the single biggest divergence in Section 3. The seeded roles are a **staff
back-office matrix** (who may review documents, manage species, triage RFQs), not the
**participant taxonomy** the brief describes. Seven of the nine required roles do not exist,
and the two that do map imperfectly. Because "buyer" is currently defined as *not a supplier
and not staff*, the brief's core requirement that one account holds multiple roles is
actively contradicted by `EnsureBuyerAccount` — a supplier signing in cannot reach the buyer
workspace at all. This needs a real `Membership(user↔organisation, role)` layer before any
Section 4–7 role (processor, artisan, carbon developer, logistics partner) can be added.

---

## 3.2 Marketplace & product profile

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Public marketplace listing + product page | BUILT | `routes/web.php:43-44`; `app/Http/Controllers/Public/ProductController.php`; `resources/views/public/products/show.blade.php` (600+ lines); `tests/Feature/ProductCatalogTest.php`, `ProductDetailTest.php` | Strong. Filters, FTS, mobile detail page. |
| Price / indicative price | BUILT | `2026_06_25_100010_create_products_table.php:22-24` | `price_amount`, `price_currency` (default XAF), `price_unit`. |
| MOQ | BUILT | same:25-26 | |
| Available sizes / dimensions | BUILT | same:29-34 | thickness, width min/max, length min/max. |
| Packaging & delivery | MISSING | — | No column, no UI. |
| Lead time | MISSING | — | Products have no lead time. Lead time exists only on `quotes.lead_time_days` and `orders.lead_time_days`, and as a company-level `delivery_days_min/max`. |
| Incoterms (export) | MISSING | — | No incoterm on `products`. Incoterms exist on `rfqs`, `quotes`, `orders` only. |
| Delivery zones (domestic) | MISSING | — | |
| Species | BUILT | `create_products_table.php:16`; `app/Models/Product.php:54` | FK to `species`. |
| Grade | BUILT | same:28 | |
| Moisture | BUILT | same:35 | free-text `moisture_content`. |
| Treatment | MISSING | — | No column. Can only live loosely in the `specifications` jsonb (same:47). |
| Finish | MISSING | — | Same. |
| Provenance: supplier, supplier location, verification status | BUILT | `Product::company()` → `companies.city/region/status/verified_at`; rendered in `products/show.blade.php:45+` | |
| Origin | PARTIAL | `create_products_table.php:36` | `origin` is a single free-text string defaulting to `'Cameroon'`, not a linked forest/concession origin. |
| Product documents (datasheet, legal origin, FSC/PEFC, phytosanitary) each with uploaded/verified/expired status | **MISSING** | `app/Models/Product.php:49-62` — only `company()`, `species()`, `images()` | There is **no product-document table and no product↔document relation at all**. The "Certificates" block on the product page is rendered from the *supplier's* verification badges: `resources/views/public/products/show.blade.php:41-43`. A visitor reading that block will believe it describes the product. |
| Product certification field | STUB | `create_products_table.php:37` | `certification` is a 150-char free-text string with no document, no issuer, no expiry and no verification. Displayed as fact at `products/show.blade.php:595-599`. |
| Reviews on products | STUB | `create_products_table.php:43-44` (`rating`, `reviews_count`, `buyers_count`) | No product review table exists. Nothing writes these columns; `Product.php:232` only reads them. `company_reviews` (`2026_08_19_150040`) is real and order-gated, but is company-level. |
| Supplier verification badge on product | BUILT | `app/Models/VerificationBadge.php`; `products/show.blade.php:41` | |
| Contact / request quotation | BUILT | `routes/web.php:110-111` (`/rfq-list/{slug}`), `resources/views/components/rfq/` | |
| Category taxonomy raw → processed → finished | PARTIAL | `app/Enums/ProductType.php`; `2026_06_26_100020_expand_products_product_type_check.php:12-15` | A **flat** 16-value list (sawn_timber…charcoal), enforced by CHECK constraint. No tree, no raw/processed/finished/construction/residue/equipment levels, no finished-goods values (no doors, furniture, flooring components), no sector collections. The brief's `Category(tree:…)` entity does not exist. |

The product record is a good *marketplace listing* and is not yet a *trusted trade record*.
Three of the brief's four product-page pillars are complete (commercial, technical,
provenance); the fourth — documents — is absent from the schema entirely, and the page
compensates by borrowing the supplier's badges, which is the most misleading surface in the
codebase. Beyond that, the missing commercial fields (lead time, incoterms, packaging,
delivery zone) are precisely the fields a buyer needs before requesting a quote, and the flat
CHECK-constrained `product_type` enum will have to become a real category tree before any
Section 4 domestic/finished-goods work can land — that is a migration with a data backfill,
not an addition.

---

## 3.3 Supplier profile & directory

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Model Supplier → Company → Verification → Products → Trade history → Reputation | BUILT | `app/Models/Company.php`; `2026_06_22_100120_create_companies_table.php` | The schema is genuinely company-centric, not User→Store. This matches the brief. |
| Company identity, location | BUILT | `create_companies_table.php:15-34` | legal/trade name, registration number, tax id, SIGIF operator id, address, region, lat/lng. |
| Verification status | BUILT | same:21,40-41 + `app/Enums/CompanyStatus.php` | |
| Rating | BUILT | `2026_06_27_100010_add_trust_metrics_to_companies_table.php:21-22` + `app/Services/CompanyReviewService.php` | Order-gated reviews recompute `rating_avg`/`rating_count`. |
| Years on Timber Hub | PARTIAL | `2026_06_26_100030:21` `years_experience` | Manually entered years in business, not years on the platform. |
| Completed orders | PARTIAL | `2026_06_27_100010:23` `orders_completed` | Column exists; a manual/aggregate field, not driven off `orders.status = completed`. |
| Response time / response rate | STUB | `2026_06_26_100030:20`, `2026_06_27_100010:24` | Columns exist; no service computes them from `rfq_company.viewed_at/responded_at`, which is the data that would support them. |
| Languages | BUILT | `2026_06_27_100010:25` jsonb | |
| Product portfolio | BUILT | `Company::products()`; directory card at `app/Services/SearchService.php:44-50` | |
| Markets served | BUILT | `2026_06_22_100160_create_company_export_markets_table.php` | |
| Specialisations | PARTIAL | derived from distinct `products.product_type` in `SearchService.php:47` | Not a first-class field; a company with no products has no specialisation. |
| Description, certifications, capacity | BUILT | `create_companies_table.php:22,25`; `verification_badges`; `annual_capacity_m3`, `annual_harvest_capacity_m3` | Capacity is two scalar m³/year columns — **not** the brief's structured `{capability, quantity, unit, period}` (that is Section 4.3, out of scope here, but the scalar is what 3.3 currently has). |
| Directory filters: species, product, location, verification, specialisation | BUILT | `app/Services/SearchService.php:22-60`; `resources/views/components/directory-filters.blade.php`; `tests/Feature/SupplierDirectoryTest.php` | |
| Directory filters: capacity, markets served | PARTIAL | `SearchService.php` `market` filter present; no capacity filter | |

3.3 is the strongest subsection in the audit and matches the brief's data model closely. The
gaps are reputation *metrics that are stored but never computed* — `response_rate_percent`,
`response_time_hours`, `orders_completed`, `on_time_delivery_percent` are all nullable columns
with no producing code, yet they render on public supplier cards when populated by seed or by
hand. That makes them trust signals with no provenance.

---

## 3.4 Supplier verification workflow (state machine)

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Not a boolean | BUILT | `app/Enums/CompanyStatus.php`; `app/Services/CompanyStatusService.php:19-26` | Correct — it is genuinely not a boolean. |
| State `registered` | PARTIAL | `CompanyStatus::Draft` | |
| States `company_info` → `business_docs` → `identity_kyc` → `forestry_legal_docs` | **MISSING** | `CompanyStatusService.php:19-26`; `create_companies_table.php:54` CHECK | The company machine is `draft → pending → verified/rejected → suspended/archived`. The four **document-gathering steps do not exist as states**. Progress through them is not modelled; there is only a completeness percentage (`app/Services/CompanyCompletenessService.php`) and per-document statuses. |
| State `compliance_review` | PARTIAL | `VerificationRequestStatus::InReview`, `app/Services/VerificationService.php:53-65` | Modelled on the *request*, not the company. |
| State `verified` | BUILT | `CompanyStatusService.php:22`; `VerificationService.php:135` | |
| State `rejected` | BUILT | `VerificationService.php:151-170` | |
| State `needs_more_info` | PARTIAL | `app/Enums/DocumentStatus.php` `NeedsCorrection`; `VerificationService.php:96` | Exists **per document** only. There is no `needs_more_info` on `verification_requests` (CHECK at `2026_06_22_110050:29` allows only `pending, in_review, approved, rejected`) and none on `companies`. An admin cannot put a *request* back to the supplier — only a single document. |
| State `published` | MISSING | — | No distinction between verified and published; `verified` is immediately public. |
| Each step stores documents, reviewer, timestamps, notes | PARTIAL | `create_company_documents_table.php:22-31` (`status`, `reviewed_by`, `reviewed_at`, `review_notes`, `rejection_reason`); `create_verification_requests_table.php:17-22` | Per-document and per-request, yes. Per-**step**, no, because steps don't exist. |
| Admin verification queue with approve / reject / request-more-info | PARTIAL | `app/Filament/Resources/VerificationRequests/`; `app/Filament/Widgets/PendingVerificationsWidget.php`; `tests/Feature/ComplianceTest.php` | Queue, approve and reject are real and tested. **Request-more-info at request level is missing** (see above). |
| Verification expiry & renewal | PARTIAL | `create_companies_table.php:41` `verification_expires_at`; `verification_badges.valid_until`; `app/Filament/Widgets/ExpiringBadgesWidget.php`; `app/Notifications/DocumentExpiring.php`; `app/Models/DocumentReminderLog.php` | Expiry is tracked and reminders are sent. There is **no renewal workflow** — no state, no route, no re-submission path distinct from a fresh submission. |
| Public badge only after `verified` | BUILT | `Company::publiclyVisible()`; `verification_badges.is_public` | |
| Document store with issuer / expiry / hash | PARTIAL | `create_company_documents_table.php:21` `checksum_sha256`, `:24-25` issue/expiry dates | Hash and dates present. **No `issuer` column.** |

The brief asks for a linear, resumable, document-gathering state machine; what exists is a
**two-machine design**: a coarse company lifecycle (`draft/pending/verified/...`) plus a
request-review lifecycle (`pending/in_review/approved/rejected`), with document granularity
underneath. It is a legitimate design and it works end to end, but it cannot answer "which
step is this supplier on?", cannot bounce a whole submission back for more information, and
has no renewal path. Both status vocabularies are pinned by Postgres CHECK constraints, so
moving to the brief's eight-state machine is a migration + backfill, not an additive change.

---

## 3.5 RFQ engine

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Post RFQ (public, no account) | BUILT | `routes/web.php:98-107`; `app/Http/Controllers/Public/RfqController.php`; `app/Services/RfqWizard.php`, `IntakeService.php`; `tests/Feature/RfqTest.php`, `RfqWizardTest.php` | Multi-step wizard, real GET URLs, email verification gate, anti-spam scoring (`AntiSpamService`, `RfqRiskService`). Strong. |
| RFQ type **Timber** | BUILT | `create_rfq_items_table.php:17` `form IN (logs,sawn,veneer,plywood,other)` | |
| RFQ type **Manufacturing** | MISSING | — | `rfqs` has **no `type` column at all** (`2026_06_22_120010`, plus later `add_title_and_project_name`, `add_user_id`). |
| RFQ type **Processing** | MISSING | — | |
| RFQ type **Project (multi-line)** | PARTIAL | `rfq_items` is a real hasMany; `2026_08_18_090000_add_title_and_project_name_to_rfqs_table.php` adds `project_name` | Multi-line is structurally supported and a project name exists, but there is no project RFQ type, no per-line delivery/deadline, and no UI framing it as a project. |
| Timber RFQ fields: species, grade, quantity/unit, dimensions, moisture | BUILT | `create_rfq_items_table.php:15-22` | |
| Field: treatment | MISSING | — | |
| Field: certification required | MISSING | — | No column on `rfqs` or `rfq_items`. |
| Field: delivery location / port, incoterms, deadline, budget | BUILT | `create_rfqs_table.php:20-26` | `destination_country_code`, `shipping_port`, `incoterm`, `deadline`, `target_amount`/`target_currency`. |
| Field: attachments | PARTIAL | `create_rfqs_table.php:34` `attachments` jsonb | A jsonb column, not the central document store of Section 9. |
| **Matching by species / category / location / capacity / verification** | **MISSING** | `app/Services/RfqTriageService.php:74-95` | `route()` takes an explicit `array $companyIds` chosen by a staff member in the Filament panel. There is **no matching engine**: no scoring, no species/location/capacity query, no auto-notification of matched suppliers. Every RFQ requires manual admin triage before any supplier sees it. `RfqCompany` (the brief's `RFQMatch`) records the routing after the fact. |
| Matched suppliers notified | BUILT | `RfqTriageService.php:91`; `app/Notifications/RfqRoutedToExporter.php` | Email only; no in-app notification. |
| Quotations | BUILT | `create_quotes_table.php`; `app/Services/QuoteService.php`; `app/Filament/Exporter/Resources/Quotes/`; `tests/Feature/QuoteTest.php` | Full lifecycle `draft/submitted/viewed/accepted/declined/withdrawn/expired`. |
| Quotation comparison table: price, grade, quantity, MOQ, lead time, incoterms, documentation, reputation | PARTIAL | `resources/views/public/rfq/responses.blade.php` (261 lines); sort control at :92-96; lowest-price highlight at :112 | A **sorted card list**, not a comparison table. Shows total, lead time, valid-until, incoterm. Does **not** show grade, quantity, MOQ, attached documentation or supplier reputation side by side. |

The RFQ intake is genuinely excellent — account-free, wizard-based, verified, spam-scored,
mirrored on the JSON API (`routes/api.php:60-80`). The engine half is not there. The brief
frames matching as "the central mechanism"; in the code it is a human in a Filament table
picking company IDs. And because `rfqs` has no `type` discriminator, three of the four RFQ
types cannot be represented at all — adding them means a new column plus reworking
`RfqWizard`, `IntakeService`, the API request classes and the exporter quoting screens.

---

## 3.6 Trade lifecycle

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Discovery → RFQ → Quotations → Comparison → Negotiation → Order → Payment → Delivery → Receipt | BUILT (as one flow) | `routes/web.php:98-260`; `tests/Feature/OrderChatLifecycleTest.php`, `ChatCommerceTest.php` | The spine exists end to end. |
| Negotiation: threaded messaging per RFQ/quotation | BUILT | `2026_08_18_130010_create_conversations_table.php`, `..._participants`, `..._messages`; `app/Services/MessagingService.php`; `routes/web.php:206-217` | Non-participants 404. |
| Offer revisions | BUILT | `2026_08_19_140020_add_revision_chain_to_quotes_table.php`; `2026_08_19_140030_create_quote_counter_offers_table.php`; `app/Models/QuoteCounterOffer.php`; `app/Services/ChatCommerceService.php` | Counter-offer chain with accept/decline/withdraw. Better than the brief asks. |
| Order status `draft` | MISSING | `create_orders_table.php:81` CHECK | Machine is `awarded → confirmed → in_production → shipped → delivered → completed / cancelled`. There is no `draft` (orders are only created by award) and no `ready`. |
| Order statuses `confirmed / in_production / shipped / delivered / completed / cancelled` | BUILT | same CHECK; `app/Services/OrderLifecycleService.php`; `routes/web.php:246-258` | Each has its own timestamp column and POST-only transition. |
| Order status `ready` | MISSING | — | |
| Order status **`disputed`** | **MISSING** | `create_orders_table.php:81` | Not in the CHECK constraint. There is no dispute state, no dispute record, and no dispute UI anywhere in the codebase. |
| Order line items | BUILT | `2026_08_18_120020_create_order_items_table.php` | |
| Order documents | BUILT | `2026_08_19_150030_create_order_documents_table.php`; `app/Services/OrderDocumentService.php`; `routes/web.php:262-265` private download | Six kinds (see 3.7). |
| Order milestones | MISSING | — | No milestone entity. Timestamps on the order are the only progress record. |
| Payments: record transactions (method, status, amount, currency) | PARTIAL | `create_orders_table.php:46-49`, `2026_08_19_150020:44-45` | Payment is **denormalised onto the order**: `payment_status` (unpaid/partially_paid/paid), `amount_paid`, `payment_method`, `payment_recorded_at`, `payment_due_at`, `payment_reference`. There is **no `payments` table** — a partial payment history cannot be represented; only a running total. |
| Currency XAF/USD/EUR | BUILT | `create_orders_table.php:83` CHECK `XAF,USD,EUR,GBP,CNY` | Default is `USD`, not XAF as the brief specifies for the primary currency. |
| Invoice generation | PARTIAL | `resources/views/public/orders/proforma.blade.php`; `routes/web.php:240-241`; `OrderLifecycleService::proforma()` | A printable **proforma** sheet rendered from the order snapshot. There is **no `invoices` table**, no invoice number series, no commercial invoice generation, no PDF. |
| Gateway / mobile money (MTN MoMo, Orange Money) | MISSING | — | Nothing. Not even a config stub. `create_receipts_table.php:11-16` states explicitly that the platform has no payment integration. |
| Supplier invoices | MISSING | — | |
| CRM (contacts, notes, follow-ups) | PARTIAL | `2026_06_22_120050_create_leads_table.php`; `app/Services/LeadFlowService.php`; `app/Filament/Exporter/Resources/Leads/` | Leads with `new/contacted/won/lost/dormant`, a free-text `notes`, `assigned_to`, `last_activity_at`, and a supplier-side Filament resource. No contact records, no follow-up scheduling, no activity timeline. `company_contacts` exists but is the *supplier's own* public contact list (`2026_06_22_100140`). |
| Analytics: views, RFQs, win rate, revenue | STUB | `app/Filament/Exporter/Widgets/LeadSummaryWidget.php:24-30` | Three counters: new / contacted / won leads. No product or profile views (nothing logs a view), no win rate, no revenue, no time series. No `AnalyticsEvent` table exists. |

The negotiation and order layers are the most complete part of the platform and are properly
built (POST-only state transitions, participation re-checked in the service, an explicit
comment at `routes/web.php:222-234` on why the buyer/supplier split is not a middleware
concern). The money layer is where it thins out: payment is six columns on `orders`, so the
platform can say "this order is 40% paid" but cannot say when, in how many instalments, or
against which reference — and reconciling that later means extracting a `payments` table and
backfilling from the existing scalars. Invoices and disputes have no data model at all.

---

## 3.7 Export module

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Export document checklist per order | PARTIAL | `2026_08_19_150030_create_order_documents_table.php:45` CHECK | Kinds are `proof_of_delivery, commercial_invoice, packing_list, bill_of_lading, certificate, other`. It is an **upload bucket, not a checklist**: nothing declares which documents an export order requires, nothing marks completeness, nothing blocks shipment on a missing document. |
| Doc: legal origin | PARTIAL | — | Only via generic `certificate`/`other`. |
| Doc: phytosanitary | PARTIAL | — | Same. |
| Doc: certificate of origin | PARTIAL | — | Same. |
| Doc: packing list | BUILT | order_documents CHECK | |
| Doc: commercial invoice | PARTIAL | order_documents CHECK | Can be *uploaded*; not generated (see 3.6). |
| Doc: customs docs | MISSING | — | |
| Shipment: port | BUILT | `2026_08_19_150020:34-35` `port_of_loading`, `port_of_discharge` | |
| Shipment: incoterms | BUILT | `create_orders_table.php:51` | |
| Shipment: carrier | BUILT | `2026_08_19_150020:27` | |
| Shipment: container | BUILT | same:31-33 vessel, voyage, container | |
| Shipment: ETD / ETA | BUILT | same:36-37 | |
| Shipment: tracking events | STUB | same:28-29 `tracking_number`, `tracking_url` | A number and an external URL. **No event stream, no position history, no `shipments` table** — shipment data is a set of columns on `orders`, so an order cannot have two shipments or a partial dispatch. |
| Delivery confirmation | BUILT | `2026_08_19_150020:40-41`; `OrderLifecycleService::deliver()`; `routes/web.php:253` | `delivered_to_name`, `delivery_location`, proof-of-delivery document. |
| Logistics partner role | MISSING | — | `SupplierType::LogisticsProvider` exists as a directory facet only. No `logistics_partner` RBAC role, no carrier entity, no booking. |

Export is currently *shipment metadata on an order*, which covers the happy path of a single
container to a single port. The brief's framing — "we handle documentation and delivery" as a
core benefit — needs the checklist to become a first-class per-order requirement list with
status per document, and needs `Shipment` extracted from `orders` before Section 7 logistics
work can attach to it.

---

## 3.8 Buyer mobile experience

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Bottom nav: Home · Marketplace · RFQ Center · Suppliers · Account | PARTIAL | `resources/views/components/layouts/app.blade.php:38-44`, rendered :430-441 | All five tabs exist with exactly the brief's labels. **The Account tab points at `url('/admin/login')`** (`:43`), not at `route('account.index')` — a signed-in buyer's Account tab sends them to the staff panel login. Its `active` is hard-coded `false`. |
| Account-area bottom nav | BUILT | `resources/views/components/layouts/account.blade.php:242-261` | A separate, correct 5-tab bar inside `/account`. |
| Favourites | MISSING | — | No table, no route, no UI. The word appears nowhere in `app/`. |
| Notifications (buyer-facing) | MISSING | — | No in-app notification surface; `databaseNotifications()` is not enabled on either Filament panel. Only three outbound notification classes exist (`app/Notifications/`). |
| Search by species / product | BUILT | `routes/web.php:47`; `app/Services/SearchService.php`; `resources/views/search.blade.php`; `tests/Feature/SearchTest.php` | |
| Responsive parity for every flow | PARTIAL | `resources/views/components/product-mobile/`; `tests/Feature/MobileAppShellTest.php`; `resources/views/components/bottom-sheet.blade.php` | Public marketplace, product detail and directory have real mobile treatments. The **supplier workspace is a Filament panel** (`/dashboard`), so supplier-side parity is whatever Filament gives, not a designed mobile flow. |
| Native buyer app transport | BUILT (v1 scope) | `routes/api.php`; `app/Services/BuyerApiScope.php`; `tests/Feature/Api/` | Browse + RFQ + quote decisions over Sanctum. Orders, messaging, documents and reorder are deliberately web-only (`routes/api.php:22-24`). |

The mobile shell matches the brief's nav spec exactly, which makes the Account tab's
`/admin/login` target the kind of one-line defect that reads as "built" from a screenshot and
fails on the first tap.

---

## 3.9 Trust layer

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Product unique ID (`CTH-CMR-IROKO-000184`) | **MISSING** | `create_products_table.php` — no such column | Products have `id` and `slug`. There is no human-readable product identifier. Reference generators exist for RFQs, quotes and orders (`app/Services/RfqReferenceGenerator.php`, `QuoteReferenceGenerator.php`, `OrderReferenceGenerator.php`) but **not for products**. |
| Product QR + barcode | **MISSING** | grep for `qr`/`QrCode`/`barcode` across `app/`, `resources/views/`, `composer.json` returns **zero hits** | No QR library is installed. No QR is generated anywhere in the platform — not for products, not for receipts, not for shipments. |
| Public product verification page | MISSING | — | No route. `/verify` and `/verify/{token}` are receipt-only. |
| Receipt: number, order no., date, supplier, amount, payment status | BUILT | `2026_08_18_120030_create_receipts_table.php`; `app/Services/ReceiptVerifier.php:70-100`; `resources/views/public/receipts/verify.blade.php`; `tests/Feature/ReceiptVerificationTest.php` | Genuinely well built: allow-listed public payload, unguessable token separate from the printed number, rate-limited, void handling, verification counter. |
| Receipt: buyer, delivery terms/date on the public page | PARTIAL (by design) | `ReceiptVerifier.php:15-22` | Deliberately withheld from strangers. Reasonable; note it differs from the brief's field list. |
| Security block: digital signature | MISSING | — | No signing anywhere. |
| Security block: **integrity hash** | **MISSING** | `create_receipts_table.php:20-39` | The receipts table has no hash column of any kind. |
| Security block: document authenticity | PARTIAL | `company_documents.checksum_sha256` (`2026_06_22_110020:21`); `order_documents.checksum` (`2026_08_19_150030:38`) | Per-file checksums are stored but never surfaced to a verifier and never chained. |
| **Hash-chained integrity record** (`prev_hash`, chain) | **MISSING** | grep for `prev_hash` / `IntegrityRecord` across `app/` and `database/` returns nothing | The only related artefact is `app/Models/ContractAcceptance.php:57`, a single-row acceptance hash explicitly documented as "an integrity check, not a signature". No chain, no `integrity_records` table. |
| Blockchain anchoring (network, tx hash, block, explorer link) | MISSING | — | Not started. Correctly deferred by the brief to an optional adapter, but note the *hash chain it would anchor* is also missing. |
| Share card for products (ID, QR, barcode, specs) | MISSING | — | Open Graph/meta tags exist site-wide (`layouts/app.blade.php:53-63`), but no product share card. |
| Reputation: verification | BUILT | `verification_badges`, `companies.status` | |
| Reputation: years active | PARTIAL | `companies.years_experience` | Self-declared. |
| Reputation: completed orders | STUB | `companies.orders_completed` | Column not computed from `orders`. |
| Reputation: response rate | STUB | `companies.response_rate_percent` | Not computed. |
| Reputation: on-time delivery | STUB | `2026_08_18_100010:21` `on_time_delivery_percent` | Not computed, though `orders.expected_delivery_at` and `delivered_at` exist to compute it. |
| Reputation: ratings | BUILT | `company_reviews` + `CompanyReviewService` | Order-gated, one review per user per order, moderated. |
| Reputation: documentation compliance | MISSING | — | |
| Reputation: dispute history | MISSING | — | No disputes exist (3.6). |
| Reputation: capacity, certifications | BUILT | `companies.annual_capacity_m3`; `verification_badges` | |
| `ReputationSnapshot(org, metrics)` (Section 10) | MISSING | — | No snapshot entity; metrics are mutable columns with no history. |

Receipt verification is real and well-engineered. Everything else in 3.9 is not. The brief's
trust layer rests on three artefacts — a product ID, a QR, and a hash chain — and **none of
the three exists**. There is no QR code anywhere in the product, which means the Phase 1
acceptance criterion ("generate a receipt whose QR resolves to a public verification page")
is currently unmet even though the verification page itself is finished. Half the reputation
panel is columns that no code writes.

---

## 3.10 Admin platform

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| Admin panel exists | BUILT | `app/Providers/Filament/AdminPanelProvider.php`; 16 resources under `app/Filament/Resources/`; `tests/Feature/AdminPanelTest.php` | The brief says "not yet recovered — must be built". **It has been built.** |
| Verification queue | BUILT | `app/Filament/Resources/VerificationRequests/`; `app/Filament/Widgets/PendingVerificationsWidget.php` | |
| Compliance review | BUILT | `app/Filament/Resources/CompanyDocuments/`; `app/Services/VerificationService.php`; `tests/Feature/ComplianceTest.php` | |
| KYC document viewer | BUILT | `app/Http/Controllers/DocumentDownloadController.php` (signed + auth, `routes/web.php:94-96`); `app/Models/DocumentAccessLog.php` | Access is logged. |
| Content / CMS: species | BUILT | `app/Filament/Resources/Species/`; `tests/Feature/SpeciesAdminTest.php` | |
| Content / CMS: pages, articles, glossary | BUILT | `Resources/Pages/`, `Resources/Articles/`, `Resources/GlossaryTerms/` | |
| Content / CMS: academy, campaigns | MISSING | — | Section 4 scope. |
| User / role management | PARTIAL | `app/Filament/Resources/Users/` | Manages users and assigns the seeded staff roles. Cannot express the brief's participant roles because they don't exist (3.1). |
| Moderation: products | BUILT | `app/Filament/Resources/Products/` + `app/Policies/ProductPolicy.php` | See note below — staff *own* products, they don't merely moderate them. |
| Moderation: reviews | BUILT | `app/Services/CompanyReviewService.php`; `company_reviews.status` | |
| Moderation: RFQs / inquiries | BUILT | `Resources/Rfqs/`, `Resources/Inquiries/`; `app/Services/InquiryTriageService.php`, `RfqTriageService.php`; `tests/Feature/InquiryModerationTest.php` | |
| Disputes | MISSING | — | No entity, no state, no screen. |
| Platform analytics | PARTIAL | `app/Filament/Widgets/PlatformOverview.php` | Stat counters. No time series, no funnel, no event store. |
| Audit log | BUILT | spatie activitylog (`2026_06_22_090514`); `app/Filament/Resources/ActivityLog/`; `app/Policies/ActivityLogPolicy.php`; used at `RfqTriageService.php:40`, `CompanyStatusService`, `VerificationService` | |
| System settings | MISSING | — | No settings resource; configuration is `.env` + `config/`. |
| Supplier workspace ("exporter" panel) | PARTIAL | `app/Providers/Filament/ExporterPanelProvider.php`; resources: Companies, CompanyDocuments, Leads, Orders, Quotes | **There is no Products resource in the exporter panel**, and `ProductPolicy::create/update/delete` all require the staff-only `products.manage` permission (`app/Policies/ProductPolicy.php:22-36`). A supplier therefore **cannot create or edit their own product listings** — every listing must be entered by platform staff in `/admin`. |

The admin platform is the pleasant surprise of this audit: the brief assumes it must be built
from scratch, and in fact it is the most complete module in Section 3. The two real gaps are
disputes (absent everywhere) and, more consequentially, the supplier-side product ownership
described above — which is a Phase 1 acceptance-criterion blocker, not a nice-to-have.

---

## 9. Cross-cutting requirements

| Brief item | Status | Evidence (file:line) | Gap notes |
|---|---|---|---|
| **i18n: English + French from the start** | **MISSING** | `lang/` contains only `en/messages.php` (40 lines) and a stray root `lang/messages.php`; `config/app.php:81` `locale => en`, `:83` fallback `en` | No French translation file, no locale switcher, no locale middleware, no `fr` route prefix. Only **4 of ~100 Blade files** use `__()` at all — every other string is hard-coded English in the template. Retrofitting i18n means touching essentially every view. |
| Currency XAF primary, USD/EUR for export | PARTIAL | `products.price_currency` default `XAF` (`create_products_table.php:23`); `orders.currency` default **`USD`** (`create_orders_table.php:37`); `quotes.currency` default `USD` | Both are supported; the trade tables default to USD, not XAF. No conversion, no FX rate storage, no display formatting per locale. |
| **One verification framework for every entity type** | PARTIAL | `verification_requests.company_id` FK (`2026_06_22_110050:14`); `company_documents.company_id` FK (`2026_06_22_110020:14`) | Both are **hard-wired to `companies` by foreign key**. There is no polymorphic `Verification(entity_type, entity_id, …)` and no `Document(owner, …)`. Verifying a product, project, artisan or retailer is not possible without restructuring these two tables. `document_types` (`2026_06_22_110010`) has no entity-type dimension either. |
| Central document store: type, issuer, expiry, verification status, hash | PARTIAL | `company_documents` has type FK, `issue_date`, `expiry_date`, `status`, `checksum_sha256`, `visibility` | Missing `issuer`. And it is **not central**: `order_documents` is a second, independent store with its own kinds and its own checksum column; `rfqs.attachments` is a third (jsonb). Three document mechanisms, no shared model. |
| Notifications: in-app | MISSING | — | `databaseNotifications()` not enabled on either panel; no notification centre in `/account`. |
| Notifications: email | PARTIAL | `app/Notifications/` (3 classes), `app/Mail/` (4 mailables) | Covers RFQ routing, company verified, document expiring, quote submitted, contact, and the two verification mails. **No email for order events** (confirmed, shipped, delivered, payment recorded) despite the brief listing them. |
| Notifications: SMS / WhatsApp | MISSING | — | |
| Unified search across products, suppliers, species | BUILT | `app/Services/SearchService.php`; `routes/web.php:47` and `api.php:56`; Postgres FTS + `pg_trgm` (`2026_06_22_100100`, `create_products_table.php:64-72`); `tests/Feature/SearchTest.php` | Real generated `tsvector` columns and GIN indexes. |
| Unified search across processors, artisans, projects | MISSING | — | Those entities don't exist (Sections 4–5). |
| Mobile-first, low-bandwidth | BUILT | `layouts/app.blade.php` (bottom tab bar, safe-area insets); `components/product-mobile/`; pagination throughout `SearchService`; `tests/Feature/MobileAppShellTest.php` | |
| Security: RBAC | PARTIAL | spatie permission (`2026_06_22_090433`); 14 policies in `app/Policies/` | Solid for staff. The participant-role half is missing (3.1). |
| Security: ownership checks on every mutation | BUILT | `app/Services/MessagingService.php` (404s non-participants), `BuyerRfqAccess.php`, `OrderLifecycleService`, `ChatCommerceService`; documented reasoning at `routes/web.php:113-118` and `:222-234` | Consistently enforced in services rather than middleware, deliberately and correctly. |
| Security: file-type validation | BUILT | `app/Services/DocumentService.php`, `OrderDocumentService.php`; `company_documents.mime_type` | |
| Security: rate limiting | BUILT | named limiters throughout `routes/web.php` (`rfq-submit`, `chat-decision`, `receipt-verify`, `order-upload`, `demo-login`, …) | Unusually thorough. |
| Security: audit log | BUILT | spatie activitylog + `Resources/ActivityLog/` | |
| SEO: server-rendered public pages | BUILT | all public routes are Blade-rendered controllers | |
| SEO: structured data | BUILT | `tests/Feature/StructuredDataTest.php`; JSON-LD in directory/product/species views; `SearchService::companyQuery()` doc-comment ties JSON-LD to the visible result set | |
| SEO: sitemap / robots / llms.txt | BUILT | `routes/web.php:88-92`; `app/Http/Controllers/Public/SitemapController.php` | |
| SEO: programmatic species/exporter pages | BUILT | `routes/web.php:163-166`; `ProgrammaticExporterController` | |

Section 9 splits cleanly: the **security, search and SEO** requirements are done to a high
standard, and the **i18n, verification-framework and document-store** requirements are not
started in any meaningful sense. i18n is the most expensive of the three because it is not a
schema problem — it is ~100 Blade templates of hard-coded English, plus no `fr` content for
species, articles or pages. The verification framework is the most *structurally* urgent,
because every Section 4–7 entity the brief wants verified (processor, artisan, product,
project, retailer, logistics company) is blocked behind un-polymorphising two FK columns.

---

## Counts

| Status | Count |
|---|---|
| BUILT | 62 |
| PARTIAL | 45 |
| MISSING | 44 |
| STUB | 9 |
| **Total items assessed** | **160** |

---

## The five things most likely to be wrongly assumed BUILT

1. **Product QR codes and product IDs — there is no QR code anywhere in the platform.**
   A grep for `qr`, `QrCode` and `barcode` across `app/`, `resources/views/` and
   `composer.json` returns zero hits; no QR library is installed. Products have no
   `CTH-CMR-…` identifier (reference generators exist for RFQs, quotes and orders but not
   products), and there is no public product verification page. Because the *receipt*
   verification page at `/verify/{token}` is finished and polished, it is very easy to read
   the trust layer as done. The Phase 1 acceptance criterion "a receipt whose QR resolves to
   a public verification page" is not met — the page exists, the QR does not.

2. **The integrity / hash-chain record does not exist.** `receipts`
   (`2026_08_18_120030_create_receipts_table.php`) has no hash column at all, and there is no
   `prev_hash`, no chain, no digital signature, and no `integrity_records` table anywhere.
   The nearest artefact, `app/Models/ContractAcceptance.php:57`, explicitly documents itself
   as "an integrity check, not a signature". Per-file SHA-256 checksums on documents are
   stored but never chained and never shown to a verifier. Anchoring to a public chain is
   correctly deferred by the brief — but the thing it would anchor is missing too.

3. **Suppliers cannot manage their own products.** The exporter panel
   (`app/Providers/Filament/ExporterPanelProvider.php`) exposes only Companies,
   CompanyDocuments, Leads, Orders and Quotes — there is **no Products resource** — and
   `app/Policies/ProductPolicy.php:22-36` gates create/update/delete behind the staff-only
   `products.manage` permission. Every listing on the marketplace must be entered by platform
   staff in `/admin`. The marketplace is rich and the supplier dashboard exists, so this looks
   complete from the outside; it breaks the Phase 1 criterion "a verified supplier can publish
   a product with documents".

4. **Product documents don't exist, and the product page shows the supplier's badges in
   their place.** `app/Models/Product.php` has exactly three relations — `company()`,
   `species()`, `images()`. There is no product-document table, no legal-origin certificate,
   no phytosanitary certificate, no per-document verified/expired status. The "Certifications"
   section of the product page is populated at
   `resources/views/public/products/show.blade.php:41-43` from
   `$company->activeBadges`, and the adjacent `certification` field
   (`create_products_table.php:37`) is unverified 150-character free text. A buyer reading
   that block reasonably concludes the *product* is certified.

5. **There is no RFQ matching engine, and three of the four RFQ types cannot be
   represented.** `RfqTriageService::route()` (`app/Services/RfqTriageService.php:74-95`)
   takes an explicit `array $companyIds` that a staff member picks by hand in Filament; there
   is no scoring, no species/location/capacity query, and no automatic supplier notification.
   Every RFQ is a manual triage task. Separately, the `rfqs` table has **no `type` column**,
   so Manufacturing, Processing and Project RFQs have nowhere to live. The intake wizard is
   excellent and heavily tested, which makes the engine behind it look more automatic than
   it is.

Honourable mention: **i18n**. `lang/` contains a single 40-line English file, only four Blade
files call `__()`, and there is no French anything. The brief lists EN/FR as a Phase 1
requirement, and it is the item whose true cost (≈100 templates plus all CMS content) is
most likely to be underestimated.

---

## Conflicts with the Section 10 target data model (need a migration path, not an addition)

Every one of these is pinned by a Postgres `CHECK` constraint or a hard foreign key, so none
can be widened by simply adding a column.

| Existing | Target (Section 10) | Migration implication |
|---|---|---|
| `verification_requests.company_id` FK, `company_documents.company_id` FK | `Verification(entity_type, entity_id, …)`, `Document(owner, …)` | Must go polymorphic before any non-company entity can be verified. Blocks Sections 4–7 wholesale. Two-step: add nullable morph columns, backfill `entity_type='company'`, then drop the FK. |
| Three separate document stores: `company_documents`, `order_documents`, `rfqs.attachments` (jsonb) | one central `Document` | Consolidation with data migration out of the jsonb column. |
| `products.product_type` — flat, 16 values, CHECK-constrained (`2026_06_26_100020`) | `Category(tree: raw\|processed\|finished\|construction\|residue\|equipment)` | Needs a `categories` table + `product.category_id` + backfill mapping each of the 16 values into the tree; the CHECK must be dropped. |
| `companies.supplier_type` — 5 values, CHECK-constrained | `Organisation(type: supplier\|processor\|manufacturer\|artisan\|buyer\|retailer\|logistics\|carbon_developer\|financier\|training_provider)` | CHECK replacement + semantic remap (`exporter`/`trader` have no target equivalent). |
| Spatie roles = staff back-office matrix; no participant roles; buyer defined negatively in `EnsureBuyerAccount` | `Role` + `Membership(user↔organisation, role)` | The negative buyer definition must be removed or a dual-role user can never reach `/account`. This is a behaviour change, not just schema. |
| Payment as 6 scalar columns on `orders` (`payment_status`, `amount_paid`, `payment_method`, `payment_recorded_at`, `payment_due_at`, `payment_reference`) | `Payment(order, method, amount, currency, status, provider_ref)` | Extract to a table and backfill one synthetic payment row per paid order; `orders.amount_paid` becomes derived. Partial-payment history before the migration is unrecoverable. |
| Shipment as ~12 columns on `orders` (`2026_08_19_150020`) | `Shipment(order, mode, port/route, carrier, events[], tracking)` | Extract + backfill. Required before Section 7 logistics can attach vehicles, drivers or positions. One order currently cannot have two shipments. |
| `orders.status` CHECK: `awarded, confirmed, in_production, shipped, delivered, completed, cancelled` | brief: `draft → confirmed → in_production → ready → shipped → delivered → completed → disputed/cancelled` | CHECK replacement; `awarded` ≈ `draft`; `ready` and **`disputed`** must be added. Disputes also need their own entity. |
| `companies.status` CHECK + `verification_requests.status` CHECK (two machines) | one 8-state supplier verification machine ending in `published` | Both CHECKs replaced, states backfilled, and `needs_more_info` promoted from document level to request level. |
| `rfqs` has no `type` | `RFQ(type: timber\|manufacturing\|processing\|project, …)` | Additive column, but `RfqWizard`, `IntakeService`, `rfq_items.form` CHECK, the API request classes and the exporter quoting screens all branch on it. |
| No `invoices`, no `AnalyticsEvent`, no `ReputationSnapshot`, no `IntegrityRecord`, no `Inventory`, no `Capacity` | all present in Section 10 | Clean additions — no conflict. |
| Trade-table currency defaults to `USD` (`orders`, `quotes`) | XAF primary | Default change is trivial; the display/formatting layer is not built either way. |
