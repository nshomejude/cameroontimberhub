# Cameroon Timber Hub — Mobile App Integration Guide (`/api/v1`)

Everything an Expo / React Native developer needs to build the mobile app —
now serving **buyers, suppliers and staff** — against the CTH backend. This
is a hand-off summary; the authoritative, always-current contract is the
generated OpenAPI spec.

### Populations

The app now serves three populations from one `/api/v1` surface:

- **buyer** — a signed-in `User` who is neither platform staff nor a member
  of a supplier company. Reaches the RFQ/quote/order/trade-assurance/
  dispute/messaging endpoints (`api.buyer` gate).
- **supplier** — a signed-in `User` who belongs to at least one company
  (`company_user`). Reaches the shared endpoints (catalogue, search,
  dashboard) plus any route behind the `api.supplier` gate. Cannot reach the
  buyer-only endpoints above — a supplier acts as a supplier, not a buyer.
- **staff** — a signed-in `User` holding one of the platform staff spatie
  roles (`super_admin`, `admin`, `verification_officer`,
  `content_manager`). Same shared-endpoint access as a supplier; the admin
  panel itself stays on the web.

`GET /api/v1/auth/me` (and the register response) tells the client which
population it is via `role`, so the client never has to infer it.

| Resource | URL |
|---|---|
| Interactive API docs | `https://www.cameroontimberhub.com/docs/api` |
| OpenAPI spec (machine-readable, generate a client from this) | `https://www.cameroontimberhub.com/docs/api.json` |
| Cross-cutting rules (auth, errors, pagination, versioning) | `docs/api/CONVENTIONS.md` in the repo |
| Generated TypeScript types | `sdks/typescript/` in the repo (`openapi-typescript` output) |

- **Base URL:** `https://www.cameroontimberhub.com/api/v1`
- **Content type:** `application/json` for every request body; every response is JSON.
- **CORS:** `Access-Control-Allow-Origin: *` on `/api/v1` — fine for a native app (no browser origin) and for Expo web.
- **Versioning:** the version is the URL prefix `/api/v1`. It is **additive-only** — new fields/endpoints can appear, nothing that exists is removed or retyped. A breaking change would ship as `/api/v2` alongside v1. Minimum 6 months' notice (via a `Sunset` response header) before anything is retired. Nothing is deprecated today.

---

## 1. Authentication

Sanctum bearer tokens. Send `Authorization: Bearer <token>` on authenticated calls.

- **Public** (no token): `products`, `products/{slug}`, `species`, `species/{slug}`, `suppliers`, `suppliers/{slug}`, `search`.
- **Any authenticated user**: `dashboard` (shape switches on `role`, see §Dashboard).
- **Buyer** (token required, `api.buyer` gate): everything under RFQs, quotes, orders, trade assurance, disputes, messaging. The token holder must be a **buyer** — a signed-in `User` who is *not* platform staff and *not* a member of a supplier company. A staff/supplier token gets `403 forbidden`; no token gets `401 unauthenticated`.
- **Supplier** (token required, `api.supplier` gate): reserved for future supplier-only endpoints — a signed-in `User` who belongs to at least one company. None of the RFQ/quote/order/trade-assurance/dispute/messaging endpoints are supplier-reachable; those stay buyer-only by design.
- Supplier **onboarding UI** and the exporter dashboard stay on the web; a supplier account can now be *created* from the mobile app (see Register below), but day-to-day supplier operations (product management, quote authoring) remain a web-only follow-up.
- Messaging (`/conversations/*`) is `api.buyer`-only today. Opening it to suppliers (so a supplier can reply to a buyer thread from the app) is a documented follow-up, not yet built — see the Messaging section.

### Register

`POST /api/v1/auth/register` &nbsp;·&nbsp; rate limit: strict (per IP)

```json
{
  "account_type": "buyer",                 // optional, "buyer" (default) or "supplier"
  "name": "Amara Okafor",
  "email": "amara@buildright.ng",
  "password": "your-password",
  "device_name": "amara-pixel-8"           // optional; labels the token, defaults to "mobile"
}
```

For `account_type: "supplier"`, add the company fields (mirrors the web
supplier-registration flow — same `RegisterAccount` action, no second write
path):

```json
{
  "account_type": "supplier",
  "name": "Sam Chia",
  "email": "sam@timberco.cm",
  "password": "your-password",
  "company_name": "Sam Timber Co",         // required for account_type: supplier
  "company_phone": "+237...",              // optional
  "company_city": "Douala",                // optional
  "company_country": "CM",                 // optional, defaults to "CM"
  "company_registration_number": "..."     // optional
}
```

`201 Created` — the response now carries the same `role`/`roles`/`company`/
`capabilities` fields as `GET /auth/me` (see below), so the client has full
identity info immediately and does not need a second call:

```json
{
  "data": {
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "user": {
      "id": 42, "name": "Amara Okafor", "email": "amara@buildright.ng",
      "email_verified": false, "email_verified_at": null,
      "created_at": "2026-09-10T08:00:00+00:00",
      "role": "buyer", "roles": [], "company": null,
      "capabilities": ["rfq.create", "quote.respond", "order.view", "dispute.file", "trade_assurance.confirm", "message.send"]
    }
  }
}
```

Only `buyer` and `supplier` are accepted here — the other web-only
account-forming types (`processor`, `artisan`, `carbon_developer`,
`logistics_partner`, `carbon_buyer`) are `422` on this endpoint. Omitting
`account_type` registers a buyer, exactly as before this change — existing
clients need no update.

### Log in

`POST /api/v1/auth/login` &nbsp;·&nbsp; rate limit: 5/min/IP + 5/min/email

```json
{ "email": "amara@buildright.ng", "password": "your-password", "device_name": "amara-pixel-8" }
```

`200 OK` — same `{ data: { token, user } }` shape as register.

> Login failure is **one generic message** on the `email` field for every cause (unknown address, wrong password, …) and the response time is flattened. Do not build UI that distinguishes "no such account" from "wrong password" — the backend deliberately won't tell you.

### Current user / log out

| | |
|---|---|
| `GET /api/v1/auth/me` | `{ "data": { …UserResource } }` — see below |
| `POST /api/v1/auth/logout` | `204 No Content`. Revokes **only the calling token** — other devices stay signed in. |

`UserResource` now carries the RBAC fields every population needs:

```json
{
  "data": {
    "id": 1, "name": "...", "email": "...", "email_verified": true,
    "email_verified_at": "...", "created_at": "...",
    "role": "buyer",           // "buyer" | "supplier" | "staff"
    "roles": [],                // raw spatie role names, e.g. ["admin"] for staff
    "company": null,            // null for buyer/staff; populated for a supplier — see below
    "capabilities": ["rfq.create", "quote.respond", "order.view", "dispute.file", "trade_assurance.confirm", "message.send"]
  }
}
```

`role` resolution: staff first (`hasAnyRole` on the platform staff spatie
roles), then company membership (`supplier`), then plain `buyer`. A user who
is both staff and a company member gets `staff` — staff wins.

For a **supplier**, `company` is populated with the user's primary company
(the `company_user` pivot row flagged `is_primary`, or the first membership
if none is flagged):

```json
"company": { "id": 5, "slug": "sam-timber-co", "name": "Sam Timber Co", "role": "owner", "status": "verified" }
```

`capabilities` is a short, explicit list — every entry is either backed by
a real Gate/Policy or is an ad-hoc boolean computed server-side because no
named policy exists yet for that action on the buyer/supplier population
(see `App\Http\Resources\Api\V1\UserResource` for exactly which is which):

| Capability | Role | Backing |
|---|---|---|
| `rfq.create`, `quote.respond`, `order.view`, `dispute.file`, `trade_assurance.confirm`, `message.send` | buyer | Ad-hoc — mirrors the `api.buyer` middleware population gate + query-level ownership scoping; no named Policy exists for these yet. |
| `product.manage` | supplier, owner/manager pivot role only | Ad-hoc — mirrors `CompanyUserRole::canManage()`. NOT backed by `ProductPolicy`'s `products.manage` permission, which is staff-only in the seeder. |
| `verification.upload` | supplier | Real — backed by `CompanyDocumentPolicy::create()`, the same policy the exporter panel's document upload flow authorises against. |
| `admin.access` | staff | Real — the exact role list that gates `User::canAccessPanel('admin')`. |

### Token storage in Expo

Store the token in `expo-secure-store` (Keychain / Keystone), not `AsyncStorage`. One token per device; on logout, delete it locally and call `/auth/logout`.

---

## 2. Conventions (read once, applies everywhere)

### Success shape

- **Single resource:** `{ "data": { … } }`
- **List:** `{ "data": [ … ], "links": { first, last, prev, next }, "meta": { current_page, last_page, per_page, total, … } }`
- **Multi-entity action:** a keyed object under `data`, e.g. `POST quotes/{ref}/accept` → `{ "data": { "quote": {…}, "order": {…} | null } }`

### Pagination

List endpoints are server-paginated with a **fixed page size** (you cannot page bigger). Pass `?page=N`. `meta.last_page` / `links.next` tell you when to stop. Some list endpoints (`products`, `species`) accept `?per_page=` up to a capped maximum and also return a `meta.facets` object for filter UIs.

### Errors — one envelope, always

Every error (any status, any cause) returns:

```json
{
  "error": {
    "code": "quote_not_actionable",
    "message": "This quote is declined and can no longer be actioned.",
    "request_id": "01J8Z9M4K7QH3RQF0P2X5N6ABC",
    "details": { "reason": ["The reason field is required."] }
  }
}
```

- **`code`** — stable `snake_case` string. **Branch on this**, never on `message`.
- **`message`** — human English, safe to show the user. Not part of the contract (wording may change).
- **`request_id`** — always present; also returned as the `X-Request-Id` response header. Log it; quote it in bug reports.
- **`details`** — present **only on `422`**: Laravel's `{ "field": ["msg", …] }` validation map. Dotted keys for nested input, e.g. `items.0.species_slug`.

| Status | `code` | When |
|---|---|---|
| `401` | `unauthenticated` | missing / invalid / expired token |
| `403` | `forbidden` | token is staff or supplier, not a buyer |
| `404` | `not_found` | no such resource **or** it belongs to another buyer (deliberately indistinguishable) |
| `409` | `quote_not_actionable`, `dispute_not_actionable`, `milestone_not_actionable`, or `conflict` | legal but not now — quote already settled/expired, illegal state transition |
| `422` | `validation_failed` (or `request_rejected` for a silent anti-spam refusal) | bad input — see `details` |
| `429` | `rate_limited` | throttled — `Retry-After` and `X-RateLimit-*` headers are set |
| `500` | `server_error` | generic message in production; the real exception is never leaked |

### Request correlation (`X-Request-Id`)

Send your own `X-Request-Id` (a ULID or UUID) with each request to tie your client logs to the server's. If you send anything malformed it's ignored and the server mints its own. Either way it comes back on the `X-Request-Id` response header and inside `error.request_id`.

### Rate limiting

- Per-endpoint limiters on the sensitive routes (login, register, RFQ create, quote decisions).
- A whole-`/api/v1` per-token budget (unauthenticated: 60/min/IP).
- On `429`: read `Retry-After` (seconds) and back off. `X-RateLimit-Remaining` is on every response — use it to pace bulk reads.

---

## 3. Endpoint reference

Auth column: 🌐 public · 🔑 buyer token.

### Catalogue — browse

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/products` | 🌐 | Product marketplace. Query: `q`, `types[]`, `species_in[]` (slugs), `region`, `supplier` (slug), `certified_only`, `best_sellers`, `sort` (`featured\|price_low\|price_high\|newest\|name`), `per_page`, `page`. Returns `data[]` + `meta.facets` (types/species/regions/flags counts) + `sort_options`. |
| GET | `/products/{slug}` | 🌐 | One product, full detail (`ProductDetailResource`). |
| GET | `/species` | 🌐 | Species encyclopedia. Query: `q`, category/property/application filters, `sort` (`popularity\|name\|density\|durability`), `per_page`, `page`. `meta.facets` included. |
| GET | `/species/{slug}` | 🌐 | One species, full detail. |
| GET | `/suppliers` | 🌐 | Verified supplier directory. Query: `q`, `types[]`, `species_in[]`, `region`, `sort`. Paginated. |
| GET | `/suppliers/{slug}` | 🌐 | One supplier profile (`SupplierDetailResource`). |
| GET | `/search` | 🌐 | Cross-entity search. Query: `q` (required). Returns grouped product / species / supplier hits. |

### Dashboard — home screen, role-switched

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/dashboard` | 🔑 any authenticated user | Stats, recent orders/quotes, top suppliers, order-status breakdown, value trend and an activity trail. Shape depends on `data.role`, always the **first key** in the payload. |

**`role: "buyer"`** — the full feed, server-computed via the same
`BuyerDashboard` service the web `/account` dashboard uses. `stats[]` and
`activity[]` intentionally omit the `url` field the web dashboard's own
payload carries internally — a Laravel `route()` URL is meaningless (and
would 404) in a native app or a WebView. `stats[]` items carry a stable
`key` instead (e.g. `active_rfqs`, `quotes_awaiting`, `active_orders`,
`orders_in_progress`, `suppliers`) so the client can map its own icon/action
without parsing `label`. `activity[]` items carry `type`
(`quote_received` | `order_status_changed`) + `reference` instead — hand
`reference` to the existing `GET /quotes/{reference}` or
`GET /orders/{reference}` endpoint to navigate; no new mapping table is
needed. `recent_orders`/`recent_quotes`/`top_suppliers` are capped at 5 and
shaped with the same `OrderResource`/`QuoteResource`/`SupplierResource`
used elsewhere. `orders_by_status` and `value_trend` are `null` for a buyer
with no orders yet (empty state), not an error.

**`role: "supplier"`** and **`role: "staff"`** — an honest **empty-but-valid**
payload, `200 OK`, not `403`/`404` (a 403 there would be indistinguishable
from a permissions bug to the client):

```json
{
  "data": {
    "role": "supplier",
    "stats": [], "recent_orders": [], "recent_quotes": [],
    "top_suppliers": [], "orders_by_status": null, "value_trend": null,
    "activity": []
  }
}
```

Field names/shapes are identical across all three roles for shared concepts
(e.g. `recent_orders` is always the same `OrderResource` shape) even though
a supplier's/staff's version is `[]` today — building the real supplier
dashboard (own RFQ inbox, sales figures, …) is a separate follow-up task.

### RFQs — the buyer's request for quotation

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/rfqs` | 🔑 | The buyer's own RFQs, paginated, newest first. |
| POST | `/rfqs` | 🔑 | Create an RFQ. Rate-limited (`throttle:api-rfq`). Payload below. → `201`, `{ data: {…RfqResource}, meta: {…} }`. |
| GET | `/rfqs/{reference}` | 🔑 | One RFQ (by `reference` e.g. `RFQ-2026-000123`), items eager-loaded. |
| POST | `/rfqs/{reference}/resend-verification` | 🔑 | Re-send the email-verification link for an RFQ still behind the gate. Own rate budget. → `202`. |
| GET | `/rfqs/{reference}/quotes` | 🔑 | Buyer-visible quotes for that RFQ. |

**Email verification gate.** A newly created RFQ is **not routed to suppliers** until the buyer clicks the link emailed to them (signed URL, opens on web). Every RFQ payload carries a `verification` block:

```json
"verification": {
  "required": true, "verified": false, "verified_at": null,
  "resend_path": "/api/v1/rfqs/RFQ-2026-000123/resend-verification"
}
```

Render "check your email — tap to resend" whenever `verification.required` is true, from *any* screen (list or detail), not only right after creation.

**Create RFQ payload:**

```json
{
  "title": "Azobe decking for marina project",          // required, 3–160
  "project_name": "Douala Marina Phase 2",              // optional
  "deadline": "2026-11-30",                              // optional, today or later
  "buyer_company": "BuildRight Nigeria Ltd",             // optional (descriptive, not identity)
  "buyer_country_code": "NG",                            // required, ISO-3166-1 alpha-2
  "destination_country_code": "NG",                      // required, alpha-2
  "shipping_port": "Apapa, Lagos",                       // optional
  "incoterm": "FOB",                                     // optional enum (FOB, CIF, CFR, EXW, …)
  "target_amount": 25000,                                // optional
  "target_currency": "USD",                              // required *if* target_amount sent (USD, EUR, XAF, …)
  "notes": "Marine-grade, KD to 16%. Need MINFOF legal-origin docs.",  // required, 20–4000 chars
  "items": [                                             // required, 1–N line items
    {
      "species_slug": "azobe",        // preferred — the slug from /species. OR species_id (int). OR species_text (free text).
      "form": "decking",              // required enum — TimberForm values (sawn_timber, decking, flooring, beams, …)
      "grade": "FAS",                 // optional
      "dimensions": "25 x 145 mm, 3–6 m",   // optional
      "quantity": 120,                // required, > 0
      "unit": "m3",                   // required enum — RfqUnit values (m3, pcs, m2, kg, …)
      "moisture_content": "KD 16%"    // optional
    }
  ]
}
```

- Identity (`buyer_name`, `buyer_email`, `buyer_phone`) is **taken from the token** and rejected if sent in the body.
- Species per line: send **`species_slug`** (from `/species`) when the timber is in the catalogue; use `species_text` for anything that isn't. An unknown/unpublished slug is a `422` on `items.N.species_slug`.
- Optional anti-spam fields the client may include: `website` (honeypot — leave empty) and `form_rendered_at` (unix seconds when the form was shown).

### Quotes

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/quotes/{reference}` | 🔑 | One quote (supplier + items eager-loaded). Marks it "viewed". |
| POST | `/quotes/{reference}/accept` | 🔑 | Accept → **awards the order**. Rate-limited (`throttle:api-decision`). → `{ data: { quote, order } }`. |
| POST | `/quotes/{reference}/decline` | 🔑 | Decline. Body: `{ "reason": "…" }` (required). Rate-limited. |

Quote payload fields to drive UI: `status` / `status_label`, `is_expired`, `is_actionable` (only show accept/decline when `true`), `valid_until`, `total_amount` + component amounts, `lead_time_days`, `payment_terms`. A `409 quote_not_actionable` means someone raced you (already settled / expired) — refetch and re-render.

### Orders

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders` | 🔑 | The buyer's orders, paginated (15/page), newest first (`OrderSummaryResource`). |
| GET | `/orders/{reference}` | 🔑 | One order with line items (`OrderResource`). |
| GET | `/orders/{reference}/shipments` | 🔑 | Shipment + checkpoint tracking timeline (`ShipmentTrackingResource`). |

Order lifecycle is in `status` / `status_label` plus the timestamp fields (`awarded_at`, `confirmed_at`, `production_started_at`, `shipped_at`, `delivered_at`, `completed_at`, `cancelled_at`) and `etd` / `eta` / `expected_delivery_at`. `has_trade_assurance` (bool|null) tells you whether to show the trade-assurance tab.

### Trade Assurance (milestone escrow-style protection)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders/{orderReference}/trade-assurance` | 🔑 | The agreement + its milestones (`TradeAssuranceResource`). |
| POST | `/orders/{orderReference}/trade-assurance/milestones/{milestoneId}/confirm` | 🔑 | Buyer confirms a milestone met. Rate-limited. `409 milestone_not_actionable` if it isn't the current one. |

### Dispute Resolution

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders/{orderReference}/disputes` | 🔑 | Disputes on that order. |
| GET | `/orders/{orderReference}/disputes/{dispute}` | 🔑 | One dispute + its message thread. |
| POST | `/orders/{orderReference}/disputes` | 🔑 | Open a dispute. Body: `{ "category": "...", "subject": "...", "description": "..." }`. Rate-limited. |
| POST | `/orders/{orderReference}/disputes/{dispute}/reply` | 🔑 | Post a message to the thread. Body: `{ "message": "..." }`. Rate-limited. |

Evidence file upload and appeal are **not** in v1 — deferred to a later release (or handled on web for now).

### Documents

Two independent families — don't confuse them:

**A supplier's own company compliance documents** (verification/legal-origin/etc — `CompanyDocument`). Gated by `api.supplier` (any signed-in user who belongs to at least one company); a buyer hitting these gets `403`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/company/documents` | 🔑 supplier | The caller's company's documents (`CompanyDocumentResource`). Empty company → `{ "data": [] }`, `200`. |
| POST | `/company/documents` | 🔑 supplier | Upload one. Multipart body: `type` (a real, active `document_types.key` — e.g. `business_registration`, `export_permit`; see `DocumentTypeSeeder`), `file`. PDF/JPG/PNG only, 10 MB max — the same limits the Filament upload form enforces. Rate-limited (`throttle:api-rfq`, the same write budget RFQ submission uses — there was no dedicated upload bucket, and this is a low-frequency write). → `201`. |
| GET | `/company/documents/{id}/download` | 🔑 supplier | Streams the file. `404` for a document that isn't the caller's company's. |

**An order's documents** (proof of delivery, invoice, packing list, ... — `OrderDocument`), visible to both order participants on the web, but **only exposed here to the order's buyer** — this route sits inside the same buyer-only `orders/{orderReference}/...` family as Trade Assurance/Disputes above, scoped through the identical `BuyerApiScope::order()` boundary. A supplier's own view of their orders' documents is a separate, larger follow-up (needs its own supplier-scoped orders listing) — not covered in this pass.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders/{orderReference}/documents` | 🔑 buyer | That order's documents (`OrderDocumentResource`). No documents → `{ "data": [] }`, `200`. |
| GET | `/orders/{orderReference}/documents/{id}/download` | 🔑 buyer | Streams the file. `404` for another buyer's order, or a document that isn't on this order. |

**Download mechanism — both families stream the bytes directly through this authenticated API response**, rather than handing back a link to the web's signed `documents.download` / `order-documents.download` routes. Those web routes sit behind session (`auth`) middleware on top of their signed-URL check, and a Sanctum-token-only mobile client never carries a web session — a signed link to them would just redirect to a login page the app can't complete. `download_url` in both resources points back at these same `/api/v1/...` endpoints (still useful as a stable, bookmarkable reference), and both controllers call straight into the existing `DocumentService`/`OrderDocumentService` — the same storage/streaming/access-logging code the web uses, so there is no second download path.

### Receipts

The buyer's own **live (non-voided) receipts** — the API counterpart of `/account/receipts` and the printable `orders/{order}/receipt` web view. `Receipt` is a hash-chained, immutable attestation record ("the platform issued this document, for this order, for this amount, on this date") — **not** proof of payment, and not the same thing as an `Invoice`. Scoped through the same buyer-owns-the-underlying-order boundary as Orders/Trade Assurance/Disputes/Documents above.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/receipts` | 🔑 buyer | The buyer's own live receipts, paginated (15/page), newest-issued-first (`ReceiptResource`). No receipts → `{ "data": [] }`, `200`. |
| GET | `/receipts/{receiptNumber}` | 🔑 buyer | One receipt by its `receipt_number` (the public, opaque identifier — not a numeric id). |

A receipt is data, not a file — the web "print view" is this same data in a Blade template with `window.print()`, so **there is no download/PDF endpoint**: `ReceiptResource` already carries everything a client needs to render its own receipt screen (`receipt_number`, `amount` as a raw decimal string, `currency`, `issued_at`, `verification_status` — always `AUTHENTIC` here, since void ones never reach this API — `verification_url`, and a minimal nested `order` reference: `reference`, `supplier_name`, `status`). Need the full order? Follow `order.reference` into `GET /orders/{reference}`.

A **voided receipt never appears** here — neither in the list nor by its number (`404`, same as another buyer's) — even though the underlying hash-chain row is immutable and stays on record. This differs from the public, unauthenticated `/verify` web page, which deliberately still reports a voided receipt's status as `VOID` (a paper-copy holder needs to be able to confirm a revocation); this authenticated buyer API instead treats a void as "not a valid receipt to show any more."

### Messaging — plain buyer<->supplier chat

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/conversations` | 🔑 | The buyer's inbox, paginated. Query: `q` (search subject/company/buyer name/message body). |
| GET | `/conversations/{id}` | 🔑 | One conversation (`ConversationResource`). |
| GET | `/conversations/{id}/messages` | 🔑 | The thread, **oldest-first** (same order the web thread renders). Query: `limit` (default/max 200). |
| POST | `/conversations/{id}/messages` | 🔑 | Post a plain-text message. Body: `{ "body": "..." }` (required, 1–4000 chars). Rate-limited (`throttle:api-decision`). → `201`. |
| POST | `/conversations/{id}/read` | 🔑 | Mark the thread read up to its latest message. → `{ data: { unread_count: 0 } }`. |

**Scope of this pass — read this before wiring a composer.** This is plain buyer<->supplier prose only: reading the inbox, reading a thread, posting text, and marking read. **Quotation issuance, counter-offers, contract acceptance, and starting an RFQ from chat are NOT exposed via this API yet** — those are commerce actions with side effects (`MessagingService::postQuotation()`/`postCounterOffer()`/`postContractAcceptance()`, `ChatCommerceService`) that need their own careful API design and stay **web-only** for now. However, if a conversation already contains one of those structured cards, `GET .../messages` still returns it — read-only — so the client can render e.g. "Quote QTE-2026-X issued" or "Order ORD-2026-Y referenced" in the thread; it just cannot be the thing that *creates* one.

Every message carries a `kind` field taken directly from `App\Enums\MessageType` (no invented values): `text`, `system`, `order_reference`, `order_status`, `product_reference`, `rfq_reference`, `quotation`, `counter_offer`, `contract_acceptance`, `proforma_invoice`, `payment_request`, `payment_confirmed`, `shipment_update`, `order_delivered`, `order_documents`, `transaction_completed`, `company_review`, `reorder_request`. Only `text` messages have a non-null `body`; every other kind carries its structured data in `payload` (the same immutable snapshot the web card partials render from) — render a fallback bubble/card per `kind` for anything the client doesn't have a dedicated view for yet, rather than assuming `body` is always populated.

A conversation id you are not a participant on (or one that does not exist) is a **`404`**, never a `403` — same enumeration-safety rule as RFQs/quotes/orders (see `MessagingService`'s class docblock): a 403 would confirm the id is real.

---

## 4. A full transaction flow

```
1. POST /auth/login                         → { token, user }
2. POST /rfqs        (Bearer token)          → 201  RFQ created, verification.required = true
   → buyer opens the emailed link (web)      → RFQ routed to matching suppliers
3. GET  /rfqs/{ref}/quotes  (poll / on open) → quotes appear as suppliers respond
4. GET  /quotes/{ref}                        → full quote; check is_actionable
5. POST /quotes/{ref}/accept                 → { data: { quote (accepted), order (awarded) } }
6. GET  /orders/{order.reference}            → track status
7. GET  /orders/{ref}/shipments              → checkpoint timeline once shipped
8. GET  /orders/{ref}/trade-assurance        → confirm milestones as they're met
```

There is **no second write path**: every mutation goes through the same domain
services the website uses, so anti-spam, the email-verification gate, the
state machines and authorization behave identically to the web.

---

## 5. Live sample responses

### `GET /api/v1/dashboard`

```json
{
  "data": {
    "role": "buyer",
    "stats": [
      { "key": "active_rfqs", "label": "Active requests", "value": 0, "hint": "Open RFQs still collecting quotes", "delta": null, "icon": "document-text" },
      { "key": "quotes_awaiting", "label": "Quotes to review", "value": 0, "hint": "Live offers you can still accept or decline", "delta": null, "icon": "tag" },
      { "key": "active_orders", "label": "Active orders", "value": 1, "hint": "Awarded and not yet completed or cancelled", "delta": null, "icon": "clipboard-document-check" },
      { "key": "orders_in_progress", "label": "In progress", "value": 0, "hint": "Confirmed, in production or shipped", "delta": null, "icon": "cube" },
      { "key": "suppliers", "label": "Suppliers engaged", "value": 1, "hint": "Distinct suppliers that have quoted for you", "delta": null, "icon": "user-group" }
    ],
    "recent_orders": [ { "reference": "ORD-2026-FJP7S", "status": "awarded", "status_label": "Awarded", "total_amount": "18500.00", "…": "…rest is OrderResource, see §3 Orders" } ],
    "recent_quotes": [ { "reference": "QTE-2026-V7PRN", "status": "accepted", "status_label": "Accepted", "total_amount": "18500.00", "supplier": { "slug": "armstrong-ohara-sarl", "name": "Armstrong-O'Hara Sarl", "…": "…rest is SupplierResource" }, "…": "…rest is QuoteResource, see §3 Quotes" } ],
    "top_suppliers": [ { "id": 1, "slug": "armstrong-ohara-sarl", "name": "Armstrong-O'Hara Sarl", "city": "Parkerport", "country_code": "CM", "…": "…rest is SupplierResource" } ],
    "orders_by_status": { "total": 1, "slices": [ { "status": "awarded", "label": "Awarded", "count": 1 } ] },
    "value_trend": null,
    "activity": [
      { "type": "quote_received", "label": "Quote received", "detail": "From Armstrong-O'Hara Sarl on RFQ-2026-DKOMJ", "at": "2026-09-11T07:29:25+01:00", "reference": "QTE-2026-V7PRN", "icon": "document-text", "tone": "timber" },
      { "type": "order_status_changed", "label": "Order awarded", "detail": "ORD-2026-FJP7S · Armstrong-O'Hara Sarl", "at": "2026-09-11T07:29:25+01:00", "reference": "ORD-2026-FJP7S", "icon": "clipboard-document-check", "tone": "forest" }
    ]
  }
}
```

Note: `url` was intentionally omitted from `stats`/`activity` (the web dashboard's own copy of these arrays carries `route()` URLs for the Blade UI, which are meaningless — and would 404 — inside a native app or WebView) in favor of `key` (stats) and `type` + `reference` (activity) navigation, using the existing per-resource endpoints (`GET /rfqs/{reference}`, `/quotes/{reference}`, `/orders/{reference}`) — no new mapping table needed. A buyer with no activity at all gets empty arrays and `orders_by_status`/`value_trend: null`, not an error.

### `GET /api/v1/conversations` (inbox)

```json
{
  "data": [
    {
      "id": 7,
      "subject": "Azobe decking for marina project",
      "topic": "rfq",
      "status": "open",
      "counterparty": { "id": 3, "slug": "armstrong-ohara-sarl", "name": "Armstrong-O'Hara Sarl", "…": "…rest is SupplierResource" },
      "last_message": { "body": "We can ship within 4 weeks of deposit.", "kind": "text", "at": "2026-09-11T09:12:03+01:00", "sender": { "id": 3, "name": "Armstrong-O'Hara Sarl", "is_own": false } },
      "unread_count": 1,
      "updated_at": "2026-09-11T09:12:03+01:00"
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1, "…": "…standard Laravel paginator meta" }
}
```

### `POST /api/v1/conversations/7/messages`

Request: `{ "body": "Can you confirm the delivery port?" }`

```json
{
  "data": {
    "id": 42,
    "kind": "text",
    "body": "Can you confirm the delivery port?",
    "payload": null,
    "sender": { "id": 101, "name": "Jane Buyer", "company_name": null, "is_own": true },
    "is_read": false,
    "created_at": "2026-09-11T09:15:44+01:00"
  }
}
```

### `GET /api/v1/company/documents`

```json
{
  "data": [
    {
      "id": 5,
      "type": "export_permit",
      "label": "Export permit",
      "status": "approved",
      "status_label": "Approved",
      "original_filename": "export-permit-2026.pdf",
      "file_size": 284112,
      "issue_date": "2026-01-10",
      "expiry_date": "2027-01-10",
      "is_expired": false,
      "uploaded_at": "2026-01-12T08:03:11+00:00",
      "reviewed_at": "2026-01-14T10:00:00+00:00",
      "download_url": "https://www.cameroontimberhub.com/api/v1/company/documents/5/download"
    }
  ]
}
```

A company with no documents yet: `{ "data": [] }`, `200`.

### `GET /api/v1/orders/ORD-2026-001/documents`

```json
{
  "data": [
    {
      "id": 21,
      "kind": "bill_of_lading",
      "label": "Bill of lading",
      "original_filename": "BL_ORD-2026-001.pdf",
      "file_size": 190532,
      "uploaded_at": "2026-05-02T14:22:01+00:00",
      "download_url": "https://www.cameroontimberhub.com/api/v1/orders/ORD-2026-001/documents/21/download"
    }
  ]
}
```

An order with no documents yet: `{ "data": [] }`, `200`.

### `GET /api/v1/products?per_page=1`

```json
{
  "data": [{
    "id": 7, "slug": "azobe-decking", "name": "Azobe Decking",
    "product_type": "decking", "product_type_label": "Decking",
    "grade": "FAS", "origin": "Cameroon", "certification": "Legal Origin Verified",
    "price": { "amount": "890000.00", "currency": "XAF", "currency_label": "FCFA",
               "unit": "m3", "unit_label": "m³", "label": "890,000 FCFA", "indicative_usd": null },
    "moq": { "quantity": "20.00", "unit": "m3", "label": "20 m³" },
    "is_featured": true, "is_best_seller": true,
    "rating": { "average": 4.9, "count": 15 },
    "primary_image_url": "https://www.cameroontimberhub.com/img/products/azobe-decking.jpg",
    "created_at": "2026-08-18T12:29:33+00:00",
    "species": { "slug": "azobe", "common_name": "Azobe" },
    "supplier": {
      "id": 2, "slug": "sangha-forest", "name": "Sangha Forest",
      "legal_name": "Sangha Forest Products Sarl", "trade_name": "Sangha Forest",
      "city": "Bertoua", "region": "East", "country_code": "CM",
      "logo_url": "https://www.cameroontimberhub.com/brand/icon-96.png",
      "supplier_type": "manufacturer", "is_featured": false,
      "verified_at": "2026-08-18T12:29:33+00:00",
      "years_experience": 12, "response_rate_percent": 88, "orders_completed": 145,
      "rating": { "average": 5, "count": 1 }
    }
  }],
  "links": { "first": "...page=1", "last": "...page=22", "prev": null, "next": "...page=2" },
  "meta": {
    "current_page": 1, "from": 1, "last_page": 22, "per_page": 1, "to": 1, "total": 22,
    "path": "https://www.cameroontimberhub.com/api/v1/products",
    "facets": {
      "types":   [ { "value": "decking", "label": "Decking", "count": 3 }, … ],
      "species": [ { "value": "ayous", "label": "Ayous", "count": 4 }, … ],
      "regions": [ { "value": "East", "label": "East", "count": 4 }, … ],
      "flags":   { "certifiedOnly": 4, "bestSellers": 1 }
    },
    "sort_options": { "featured": "Featured", "price_low": "Price (low to high)",
                      "price_high": "Price (high to low)", "newest": "Newest listings",
                      "name": "Name (A–Z)" }
  }
}
```

### `GET /api/v1/species?per_page=1`

```json
{
  "data": [{
    "id": 1, "slug": "ayous", "common_name": "Ayous",
    "scientific_name": "Triplochiton scleroxylon", "family": "Malvaceae",
    "trade_names": ["Obeche", "Wawa", "Abachi", "Samba", "African whitewood"],
    "commercial_category": "primary_hardwood",
    "commercial_category_label": "Primary / Principal Hardwood",
    "is_cites_listed": false, "is_promoted": false,
    "log_export_status": "unknown",
    "log_export_status_label": "Unknown / not verified",
    "density_kg_m3_min": 320, "density_kg_m3_max": 450, "density_range": "320–450 kg/m³",
    "durability_class": "Class 5 (Not Durable)", "janka_hardness": 1900,
    "is_premium": false, "products_count": 4
  }],
  "links": { "first": "...page=1", "last": "...page=52", "prev": null, "next": "...page=2" },
  "meta": { "current_page": 1, "last_page": 52, "per_page": 1, "total": 52,
            "facets": { "categories": [...], "properties": [...], "applications": [...], "regions": [...] },
            "sort_options": { "popularity": "Popularity", "name": "Name (A–Z)",
                              "density": "Density (heaviest)", "durability": "Durability" } }
}
```

### A `422` validation error

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "request_id": "01JBX8...",
    "details": {
      "notes": ["The requirement details must be at least 20 characters."],
      "items.0.quantity": ["The quantity must be greater than 0."]
    }
  }
}
```

---

## 6. Suggested Expo client setup

```ts
// api.ts
const BASE = "https://www.cameroontimberhub.com/api/v1";

async function api(path: string, init: RequestInit = {}, token?: string) {
  const res = await fetch(`${BASE}${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-Request-Id": crypto.randomUUID(),          // ties your logs to the server's
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  });

  const body = res.status === 204 ? null : await res.json();

  if (!res.ok) {
    // body.error = { code, message, request_id, details? }
    throw Object.assign(new Error(body?.error?.message ?? "Request failed"), {
      status: res.status,
      code: body?.error?.code,
      details: body?.error?.details,
      requestId: body?.error?.request_id,
    });
  }
  return body;                                       // { data, links?, meta? }
}
```

- Generate typed models from `https://www.cameroontimberhub.com/docs/api.json` with `openapi-typescript` (already wired in the repo under `sdks/typescript/` — point your app at that package or regenerate).
- On `401`, clear the stored token and send the user to the login screen.
- On `429`, respect `Retry-After`.
- Treat `404` as "not found or not yours" — don't imply the resource exists.
