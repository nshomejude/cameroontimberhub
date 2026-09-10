# Cameroon Timber Hub — Mobile App Integration Guide (`/api/v1`)

Everything an Expo / React Native developer needs to build the **buyer app**
against the CTH backend. This is a hand-off summary; the authoritative,
always-current contract is the generated OpenAPI spec.

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
- **Buyer** (token required): everything under RFQs, quotes, orders, trade assurance, disputes. The token holder must be a **buyer** — a signed-in `User` who is *not* platform staff and *not* a member of a supplier company. A staff/supplier token gets `403 forbidden`; no token gets `401 unauthenticated`.
- Supplier onboarding and the exporter dashboard stay on the web. The mobile app is buyer-only.

### Register

`POST /api/v1/auth/register` &nbsp;·&nbsp; rate limit: strict (per IP)

```json
{
  "name": "Amara Okafor",
  "email": "amara@buildright.ng",
  "password": "your-password",
  "password_confirmation": "your-password",
  "device_name": "amara-pixel-8"          // optional; labels the token, defaults to "mobile"
}
```

`201 Created`:

```json
{
  "data": {
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "user": {
      "id": 42, "name": "Amara Okafor", "email": "amara@buildright.ng",
      "email_verified": false, "email_verified_at": null,
      "created_at": "2026-09-10T08:00:00+00:00"
    }
  }
}
```

`account_type` is forced to `buyer` server-side — you cannot register a supplier here.

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
| `GET /api/v1/auth/me` | `{ "data": { …UserResource } }` |
| `POST /api/v1/auth/logout` | `204 No Content`. Revokes **only the calling token** — other devices stay signed in. |

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
