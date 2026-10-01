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
  "account_type": "buyer",                 // optional, default "buyer" — see accepted values below
  "name": "Amara Okafor",
  "email": "amara@buildright.ng",
  "password": "your-password",
  "terms_accepted": true,                  // REQUIRED — must be true (consent to the terms of service)
  "device_name": "amara-pixel-8"           // optional; labels the token, defaults to "mobile"
}
```

`terms_accepted` is **required** (`accepted` rule: `true`, `1`, `"yes"`, `"on"`).
Show your terms screen/checkbox and send it; a missing or false value is a
`422` on `terms_accepted`. The server records the acceptance time and the
current terms version on the account. `email` is stored trimmed and
lower-cased; uniqueness is case-insensitive.

**Email verification.** Registration sends a verification email. The user can
use the app straight away (login is not blocked), but until `email_verified`
is `true`:
- RFQs previously submitted as a guest under that address are **not** attached
  to the account (they are attached automatically when the link is clicked);
- starting a new supplier conversation on the web is refused.

The link in the email is a signed web URL that works without a session, so it
can be opened from the phone's mail app. To resend it:
`POST /api/v1/auth/email/verification-notification` (auth, 6/min) →
`202 { "data": { "email_verified": false, "message": "..." } }`.
Re-fetch `GET /auth/me` to pick up `email_verified: true`.

For a company-forming `account_type` (`supplier`, `processor`, `artisan`,
`logistics_partner`), add the company fields (mirrors the web
supplier-registration flow — same `RegisterAccount` action, no second write
path):

```json
{
  "account_type": "supplier",
  "name": "Sam Chia",
  "email": "sam@timberco.cm",
  "password": "your-password",
  "terms_accepted": true,                  // required
  "company_name": "Sam Timber Co",         // required for company-forming account types
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

**Accepted `account_type` values** (same list as the web register form —
both use `RegisterAccount::rules()`):

| Value | Company created? |
|---|---|
| `buyer` (default when omitted/empty) | no |
| `supplier` | yes — `company_name` required |
| `processor` | yes — `company_name` required |
| `artisan` | yes — `company_name` required |
| `logistics_partner` | yes — `company_name` required |

`carbon_developer` and `carbon_buyer` are **not accepted at launch** (carbon
credits are not live yet): they return `422` with
`error.details.account_type = ["Carbon accounts are coming soon and cannot be registered yet."]`.
They are re-enabled server-side by `SIGNUP_CARBON_ENABLED=true`
(`config('timber.signup.carbon_enabled')`), after which `carbon_developer`
is company-forming and `carbon_buyer` is not. Any other value is a `422`.
Omitting `account_type` registers a buyer — existing clients need no update.

### Log in

`POST /api/v1/auth/login` &nbsp;·&nbsp; rate limit: 5/min/IP + 5/min/email

```json
{ "email": "amara@buildright.ng", "password": "your-password", "device_name": "amara-pixel-8" }
```

`200 OK` — same `{ data: { token, user } }` shape as register.

> Login failure is **one generic message** on the `email` field for every cause (unknown address, wrong password, …) and the response time is flattened. Do not build UI that distinguishes "no such account" from "wrong password" — the backend deliberately won't tell you.

> **Staff accounts must have 2FA.** When `STAFF_REQUIRE_2FA` is on (the
> production default), a staff user (any platform staff role) **without**
> confirmed two-factor authentication gets `403` with
> `error.code = "two_factor_enrollment_required"` from `POST /auth/login` —
> after a correct password, and no token is issued. The message points to
> the web enrolment page (`/security/two-factor`). Show it with an "Open in
> browser" button; the app cannot enrol staff itself. Staff with 2FA get the
> normal `two_factor_required` challenge. Every `/api/v1/staff/*` route
> enforces the same rule (same `403` code) for tokens minted earlier.

### Current user / log out

| | |
|---|---|
| `GET /api/v1/auth/me` | `{ "data": { …UserResource } }` — see below |
| `POST /api/v1/auth/logout` | `204 No Content`. Revokes **only the calling token** — other devices stay signed in. |
| `POST /api/v1/auth/password` | `204`. Changes the password and revokes **every other token** of the user (the calling token stays valid). |
| `POST /api/v1/auth/reset-password` | Revokes **all** tokens of the user (and a web reset does the same) — every device must sign in again. |
| `POST /api/v1/auth/two-factor/enable` | When 2FA is already confirmed, `password` (current password) is **required**, otherwise `422` — re-enrolling would otherwise silently disable 2FA. `two-factor/confirm` is rate-limited (6/min). |

`UserResource` now carries the RBAC fields every population needs:

```json
{
  "data": {
    "id": 1, "name": "...", "email": "...", "email_verified": true,
    "email_verified_at": "...", "created_at": "...",
    "role": "buyer",           // "buyer" | "supplier" | "staff"
    "account_type": "buyer",   // finer-grained, see below
    "roles": [],                // raw spatie role names, e.g. ["admin"] for staff
    "company": null,            // null for buyer/staff; populated for a supplier — see below
    "capabilities": ["rfq.create", "quote.respond", "order.view", "dispute.file", "trade_assurance.confirm", "message.send"]
  }
}
```

`role` resolution: staff first (`hasAnyRole` on the platform staff spatie
roles), then company membership (`supplier`), then plain `buyer`. A user who
is both staff and a company member gets `staff` — staff wins.

`account_type` (additive) is the account type chosen at registration, one of
`buyer`, `supplier`, `processor`, `artisan`, `logistics_partner`,
`carbon_developer`, `carbon_buyer`, `staff`. It is read from the
account-capability role assigned at signup; a company member without one
falls back to its company's `type` (`logistics` -> `logistics_partner`),
anything else is `buyer`. `role` stays the coarse population switch
(`buyer`/`supplier`/`staff`) — use `account_type` to pick the app's
home-screen flavour. It is returned by `/auth/me`, `register`/`login` (same
`UserResource`) and `GET /dashboard`.

For a **supplier**, `company` is populated with the user's primary company
(the `company_user` pivot row flagged `is_primary`, or the first membership
if none is flagged):

```json
"company": { "id": 5, "slug": "sam-timber-co", "name": "Sam Timber Co", "role": "owner", "status": "verified", "type": "manufacturer" }
```

`company.type` is the real `App\Enums\OrganisationType` value (`supplier`,
`processor`, `manufacturer`, `artisan`, `buyer`, `retailer`, `logistics`,
`carbon_developer`, `financier`, `training_provider`) — `null` when there is
no company. Use it to branch on the actual organisation type (e.g. show the
Fleet section below only for `"logistics"`) instead of guessing from
`capabilities`.

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

### Demo logins

Mobile mirror of the web `/login` "Explore a demo account" buttons — lets a
reviewer sign into every role on their own phone without a password. The
whole feature sits behind `App\Features\DemoLoginsEnabled`
(`DEMO_LOGINS_ENABLED`, default **false**); both endpoints below stay live
regardless, but `demo-personas` returns an empty list and `demo-login/*`
`403`s when the flag is off.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/auth/demo-personas` | none | The available personas. Always `200`; `{ "data": [] }` when demo logins are disabled. |
| POST | `/auth/demo-login/{persona}` | none | Sign in as that persona. `{ persona }` must be one of the keys `demo-personas` returned. Rate-limited (`throttle:demo-login`, same budget as the web route). |

```json
// GET /auth/demo-personas → 200
{
  "data": [
    { "key": "buyer", "label": "Demo Buyer", "description": "Requests, quotes and orders", "icon": "shopping-bag" },
    { "key": "supplier", "label": "Demo Supplier", "description": "A verified exporter’s panel", "icon": "building-office-2" },
    { "key": "admin", "label": "Demo Admin", "description": "The staff moderation panel", "icon": "shield-check" },
    { "key": "logistics", "label": "Demo Logistics", "description": "A fleet operator's vehicles and drivers", "icon": "truck" },
    { "key": "pending_supplier", "label": "Demo Pending Supplier", "description": "A supplier account awaiting verification", "icon": "clock" }
  ]
}
```

`POST /auth/demo-login/{persona}` returns the **exact same shape** as
`POST /auth/login` — `{ data: { token, user } }` — minted through the same
token-issuing code (`AuthController::issueTokenFor()`), so the client can
reuse its normal post-login handling unmodified. An unknown `{persona}` is a
clean `404`.

Personas and what they demonstrate:

| Persona | Role | What's seeded |
|---|---|---|
| `buyer` | buyer | Owns the seeded RFQs/orders/receipts. |
| `supplier` | supplier | Owner of a verified exporter company, with fleet, leads and a routed RFQ. |
| `admin` | staff | Real `super_admin` — full `/admin` access. |
| `logistics` | supplier | Owner of a `company.type: "logistics"` company with seeded vehicles/drivers — exercises the Fleet endpoints. |
| `pending_supplier` | supplier | Owner of a company with `company.status: "pending"` — exercises the pending/read-only verification banner. |

No password is ever involved, transmitted, or displayed for any persona —
`DemoLoginController` resolves the literal `{persona}` key to a config-defined
email and mints a token directly, the same way the web controller calls
`Auth::login()` without touching a password.

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
| GET | `/search/suggest` | 🌐 | Instant (type-ahead) search — see below. Query: `q`, `types`, `limit`. |

### Instant search (`GET /api/v1/search/suggest`)

Type-ahead suggestions for the search bar. Public, no token needed (a token
is accepted and only changes the throttle key).

**Query**

| Param | Default | Notes |
|---|---|---|
| `q` | — | The partial query. Under **2 characters** returns empty groups (200, not an error). Trimmed, lower-cased, capped at 64 chars. |
| `types` | `products,suppliers,species` | Comma-separated subset. Unknown values → 422. |
| `limit` | `5` | Per group, 1–5. Over 5 → 422. |

**Matching** — case- and accent-insensitive substring match (`ebene` finds
"Ébène", `IROKO` finds "Iroko"). Every space-separated word must match.
A product matches on its own name **or its species' common / scientific /
French name / synonyms, or its supplier's name** — so typing "iroko" lists
every Iroko listing even if the seller named it "Sawn beams 50x150".
Names starting with the query rank first. Only public data is returned:
active products of publicly visible suppliers, publicly visible suppliers,
published species.

**Client guidance**

- **Min chars:** don't call below `meta.min_chars` (2) — clear the dropdown instead.
- **Debounce 250 ms** after the last keystroke.
- **Cancel the in-flight request** on every new keystroke (`AbortController`
  with `fetch`/axios `signal`), and ignore any response whose `meta.query`
  no longer matches the (normalised) text in the box.
- **Submit / "See all"** → the full paginated `GET /api/v1/search?q=…`
  (`meta.search_url` is the website equivalent).
- Tap a suggestion → `products/{slug}`, `suppliers/{slug}` or `species/{slug}`.
  Each row also carries the website `url` (for share sheets / deep links).
- **Caching:** responses are `Cache-Control: public, max-age=60` with an
  `ETag`; send `If-None-Match` to get a `304`. Server-side results are
  cached 60 s per normalised query.
- **Throttle:** own limiter, **120 requests/min** per user (or per IP when
  anonymous) — not the shared 60/min anonymous limit. On `429`, stop
  suggesting until `Retry-After` elapses; the search button still works.

**Example**

```http
GET /api/v1/search/suggest?q=iroko
Accept: application/json
```

```json
{
  "data": {
    "products": [
      {
        "id": 42,
        "slug": "sawn-beams-50x150",
        "name": "Sawn beams 50x150",
        "species": "Iroko",
        "company_name": "Scierie Nkongsamba",
        "image_url": "https://cameroontimberhub.com/storage/products/abc.jpg",
        "price_label": "650,000 FCFA",
        "url": "https://cameroontimberhub.com/marketplace/sawn-beams-50x150"
      }
    ],
    "suppliers": [],
    "species": [
      {
        "slug": "iroko",
        "name": "Iroko",
        "scientific_name": "Milicia excelsa",
        "url": "https://cameroontimberhub.com/species/iroko"
      }
    ]
  },
  "meta": {
    "query": "iroko",
    "min_chars": 2,
    "search_url": "https://cameroontimberhub.com/search?q=iroko"
  }
}
```

Supplier rows: `{ "slug", "name", "logo_url", "city", "verified": true, "url" }`.
`image_url` and `price_label` may be `null` (no photo / price on request).

### Dashboard — home screen, role-switched

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/dashboard` | 🔑 any authenticated user | Stats, recent orders/quotes, top suppliers, order-status breakdown, value trend and an activity trail. Shape depends on `data.role`, always the **first key** in the payload. |

Every payload also carries `account_type` (same value as `/auth/me`) and
`company` (the same `{id, slug, name, role, status, type}` object as
`UserResource.company` for a supplier; `null` for buyer/staff).

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
| POST | `/rfqs/{reference}/cancel` | 🔑 | Withdraw the buyer's own RFQ. Allowed only while its status is `new`, `in_review` or `approved` **and** no order has been awarded on it; the RFQ moves to `closed` (there is no separate `cancelled` status). Every supplier it was routed to is notified (database + mail, type `rfq_withdrawn`). → `200` `RfqResource`; `409` `rfq_not_cancellable` when already awarded/closed/rejected; another buyer's reference `404`. `throttle:api-decision`. |

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
  "type": "export",                                      // optional: export (default) | domestic_manufacturing | transport
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

- `type` picks the RFQ flow: `export` (default when omitted), `domestic_manufacturing` (manufacturing / local procurement — offered to manufacturers, artisans, processors) or `transport` (offered to logistics companies). Unknown values are a `422`. Every RFQ payload returns `type` and `type_label`.
- Identity (`buyer_name`, `buyer_email`, `buyer_phone`) is **taken from the token** and rejected if sent in the body.
- Species per line: send **`species_slug`** (from `/species`) when the timber is in the catalogue; use `species_text` for anything that isn't. An unknown/unpublished slug is a `422` on `items.N.species_slug`.
- Optional anti-spam fields the client may include: `website` (honeypot — leave empty) and `form_rendered_at` (unix seconds when the form was shown).

### Quotes

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/quotes/{reference}` | 🔑 | One quote (supplier + items eager-loaded). Marks it "viewed". |
| POST | `/quotes/{reference}/accept` | 🔑 | Accept → **awards the order**. Rate-limited (`throttle:api-decision`). → `{ data: { quote, order } }`. |
| POST | `/quotes/{reference}/decline` | 🔑 | Decline. Body: `{ "reason": "…" }` (required). Rate-limited. |

`conversation_id` (int|null) is the chat thread the quote was shared into (its quotation card), on both the buyer `QuoteResource` and the supplier `SupplierQuoteResource` — use it for `POST /conversations/{id}/quotes/{quote}/counter` (counter-offer) / `accept` / `decline`. `null` means the quote lives only outside chat; use the plain `/quotes/{reference}/accept|decline` endpoints.

Quote payload fields to drive UI: `status` / `status_label`, `is_expired`, `is_actionable` (only show accept/decline when `true`), `valid_until`, `total_amount` + component amounts, `lead_time_days`, `payment_terms`. A `409 quote_not_actionable` means someone raced you (already settled / expired) — refetch and re-render.

### Orders

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders` | 🔑 | The buyer's orders, paginated (15/page), newest first (`OrderSummaryResource`). |
| GET | `/orders/{reference}` | 🔑 | One order with line items (`OrderResource`). |
| GET | `/orders/{reference}/shipments` | 🔑 | Shipment + checkpoint tracking timeline (`ShipmentTrackingResource`): `id`, `waybill_number`, `carrier_status` / `carrier_status_label` (see Logistics), `current_status`, `checkpoints[]` (`id`, `status`, `location`, `notes`, `has_photo`, `photo_url`, `occurred_at`, `recorded_at`). |
| GET | `/orders/{reference}/shipments/{shipment}/checkpoints/{checkpoint}/photo` | 🔑 | Streams the checkpoint proof photo (image bytes with its real `Content-Type`, `Cache-Control: private`). Use the `photo_url` from the timeline (only set when `has_photo`). Buyer of the order only; anything else `404`. |

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
| POST | `/orders/{orderReference}/disputes/{dispute}/evidence` | 🔑 | **multipart/form-data.** `description` (required, ≤2000 chars) + optional `file` (PDF/JPEG/PNG/WebP, ≤15 MB — same rules as the web form). Allowed while the dispute is `opened`/`evidence_pending`, otherwise `409` `dispute_not_actionable`. → `201` with the refreshed `DisputeResource`. `throttle:order-upload`. |
| POST | `/orders/{orderReference}/disputes/{dispute}/appeal` | 🔑 | Appeal a **resolved** dispute (no body). → `200` `DisputeResource` with `status: appealed`; any other status `409` `dispute_not_actionable`. Rate-limited. |
| GET | `/disputes` | 🔑 | All of the buyer's disputes across every order, newest first, paginated (20/page). Each row carries `order_reference` so the client can open `/orders/{orderReference}/disputes/{id}`. |

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

### Reorder — repeating a past purchase

Buyer-initiated only. A reorder does **not** clone the order — it re-enters the same audited path a fresh RFQ takes (buyer requests -> admin triages/routes -> supplier quotes with today's prices -> buyer accepts -> a brand-new `Order`). See `App\Services\ReorderService`'s class docblock for the full model; this API exposes only the buyer's first step, same boundary as Messaging above — the supplier's confirmation step stays web-only for now.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders/{orderReference}/reorder` | 🔑 buyer | Eligibility + prefill check. Always `200`. `data.eligible` (bool); when `false`, `data.reason` carries the real refusal text. `data.in_progress` + `data.existing_rfq_reference` say whether a reorder for this order is already open. `data.lines` (prefillable line items, `null` when not eligible). |
| POST | `/orders/{orderReference}/reorder` | 🔑 buyer | Raise the request. Body: `{ "quantities": { "<order_item_id>": 80 }, "shipping_port": "...", "deadline": "YYYY-MM-DD", "notes": "..." }` (all optional). → `201` with `data.message` (the `reorder_request` card, `MessageResource`) and `data.conversation.id` to open the thread. Rate-limited (`throttle:api-decision`). |

No price field exists on the request body, and none is read — a buyer never sets the price of their own reorder (see `ReorderService`, "Pricing — the crux"). The previous unit price rides along in `data.lines[].previous_unit_price` as reference only.

`POST` is idempotent for the ordinary case: while a reorder is already open, a second call returns the **same** `data.message.id` rather than creating a duplicate — mirrors `GET .../reorder`'s `in_progress` flag, so there is no state where the client needs to guess. Only the genuine concurrent-request race (two POSTs landing at once) surfaces as `409 reorder_already_open`; any other domain refusal (order not eligible after all, no line items to repeat) is `422 reorder_not_eligible`.

### Reviews — rating a supplier on a completed order

Buyer-initiated only. A review is anchored to a specific order (`App\Services\CompanyReviewService`): to review a supplier a buyer must own the order and the order's status must be `completed`. One review per order — a database UNIQUE index on `order_id` backs the rule the service already checks.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/orders/{orderReference}/review` | 🔑 buyer | Eligibility check. Always `200`. `data.eligible` (bool); when `false`, `data.reason` carries the real refusal text (e.g. "You can review a supplier once the order is completed." or "You have already reviewed this order."). `data.existing_review` is the buyer's own prior review on this order (`id`, `rating`, `body`, `status`, `status_label`, `created_at`), or `null`. |
| POST | `/orders/{orderReference}/review` | 🔑 buyer | Submit the review. Body: `{ "rating": 1-5 (required), "body": "..." (optional, up to 5000 chars) }`. → `201` with the created review (`CompanyReviewResource`). Rate-limited (`throttle:api-decision`). |

A review is **published immediately** — there is no pending-moderation state on creation (`CompanyReviewService::create()` always writes `status: published`); `status`/`status_label` are still surfaced honestly in case moderation later takes it down. Publishing a review recomputes the supplier's `rating_avg`/`rating_count` automatically (`CompanyReviewService::recompute()`, called from inside `create()`).

A second `POST` on the same order — by the same buyer or a race between two requests — is blocked by the service's real guard (the eligibility check, or the UNIQUE index catching a concurrent write) and surfaces as `422 review_not_eligible`, never a silent duplicate.

### Messaging — plain buyer<->supplier chat

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/conversations` | 🔑 | The buyer's inbox, paginated. Query: `q` (search subject/company/buyer name/message body). |
| POST | `/conversations` | 🔑 buyer only | Start (or reuse) a thread with a supplier company and post the first message. Body: `{ "company": "sam-timber-co" \| 5, "body": "...", "product_id"?: 12, "order"?: "ORD-2026-000001", "topic"?: "general\|rfq\|order\|product\|support", "subject"?: "..." }`. `company` is the slug or numeric id; `product_id` must belong to that company and `order` must be the caller's own order with that company (else `422`). → `201` with `ConversationResource` for a new thread, `200` when an open thread with the same company/order was reused. **Unavailable supplier:** a company that is not publicly visible (suspended, archived, pending, rejected, or missing its required profile/badge) is refused with `422` `company_unavailable` — even when an old thread with it exists. **Thread reuse:** if the buyer already has a non-closed thread with the same company *and* the same order (or both without an order), that thread is reused and `body` is appended to it (`200`); `product_id`/`topic`/`subject` do not create a separate thread. A closed thread is never reused — a new one is opened (`201`). **Unverified email:** a NEW thread is refused with `403` `email_unverified` (reusing an existing open one still works) — prompt the user to verify (`POST /auth/email/verification-notification`). Rate-limited (`throttle:message-start`, 30/h). A supplier account gets `403` (suppliers reply in existing threads). |
| GET | `/conversations/{id}` | 🔑 | One conversation (`ConversationResource`). |
| GET | `/conversations/{id}/messages` | 🔑 | The thread, **oldest-first** (same order the web thread renders). Query: `limit` (default/max 200). |
| POST | `/conversations/{id}/messages` | 🔑 | Post a plain-text message. Body: `{ "body": "..." }` (required, 1–4000 chars). Rate-limited (`throttle:api-decision`). → `201`. |
| POST | `/conversations/{id}/read` | 🔑 | Mark the thread read up to its latest message. → `{ data: { unread_count: 0 } }`. |

**Scope of this pass — read this before wiring a composer.** This is plain buyer<->supplier prose only: reading the inbox, reading a thread, posting text, and marking read. **Quotation issuance, counter-offers, contract acceptance, and starting an RFQ from chat are NOT exposed via this API yet** — those are commerce actions with side effects (`MessagingService::postQuotation()`/`postCounterOffer()`/`postContractAcceptance()`, `ChatCommerceService`) that need their own careful API design and stay **web-only** for now. However, if a conversation already contains one of those structured cards, `GET .../messages` still returns it — read-only — so the client can render e.g. "Quote QTE-2026-X issued" or "Order ORD-2026-Y referenced" in the thread; it just cannot be the thing that *creates* one.

Every message carries a `kind` field taken directly from `App\Enums\MessageType` (no invented values): `text`, `system`, `order_reference`, `order_status`, `product_reference`, `rfq_reference`, `quotation`, `counter_offer`, `contract_acceptance`, `proforma_invoice`, `payment_request`, `payment_confirmed`, `shipment_update`, `order_delivered`, `order_documents`, `transaction_completed`, `company_review`, `reorder_request`. Only `text` messages have a non-null `body`; every other kind carries its structured data in `payload` (the same immutable snapshot the web card partials render from) — render a fallback bubble/card per `kind` for anything the client doesn't have a dedicated view for yet, rather than assuming `body` is always populated.

A conversation id you are not a participant on (or one that does not exist) is a **`404`**, never a `403` — same enumeration-safety rule as RFQs/quotes/orders (see `MessagingService`'s class docblock): a 403 would confirm the id is real.

### Supplier — RFQ inbox, quotes, sales orders & fulfilment

All under `/supplier/...` behind `api.supplier` (company membership). Another
company's reference is always a `404`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/supplier/rfqs` | RFQs routed to the caller's company. Query: `status` (`sent\|viewed\|responded\|declined`), `type` (`export\|domestic_manufacturing\|transport`). Each item carries `type`. |
| GET | `/supplier/rfqs/{reference}` | One routed RFQ. |
| POST | `/supplier/rfqs/{reference}/quote` | Submit a quote (`throttle:api-decision`). Also accepts an RFQ from the caller's open-requests board (below): it is self-routed to the caller's company first, then quoted. |
| GET | `/supplier/rfq-board` | Open buyer requests: approved, open RFQs matching the caller's company (species handled + RFQ type targeting) that are **not** routed to it yet. Paginated (15). Query: `type` (`export\|domestic_manufacturing\|transport`), `species` (species slug). Buyer name/company/email, notes and attachments are **omitted**. Shown to verified **and pending-verification** companies; empty for draft/suspended/rejected/archived companies or when the plan has `leads_receive` off. Each item carries `can_respond` and `contact_locked: true`. |
| POST | `/supplier/rfq-board/{reference}/express-interest` | Self-route a board RFQ to the caller's company (`throttle:api-decision`). Returns the routed RFQ (`SupplierRfqResource`, as `/supplier/rfqs/{reference}`). `404` if the RFQ is not on the caller's board. Optional: quoting a board RFQ directly does the same. |
| GET | `/supplier/quotes`, `/supplier/quotes/{reference}` | The caller's quotes; each carries `conversation_id` (see Quotes). |
| GET | `/supplier/orders`, `/supplier/orders/{reference}` | Sales orders (`SupplierOrderResource`), with `conversation_id` and `actions[]`. |
| GET | `/supplier/orders/{reference}/documents` | The order's documents (`OrderDocumentResource`, same shape as the buyer's). |
| GET | `/supplier/orders/{reference}/documents/{document}/download` | Stream one document. |
| GET | `/supplier/orders/{reference}/shipments` | Shipment + checkpoint timeline (`ShipmentTrackingResource`). |
| POST | `/supplier/orders/{reference}/confirm` | `awarded` -> `confirmed`. |
| POST | `/supplier/orders/{reference}/production` | `confirmed` -> `in_production`. |
| POST | `/supplier/orders/{reference}/ship` | -> `shipped`. Optional tracking fields: `carrier`, `tracking_number`, `tracking_url`, `shipping_method`, `vessel_name`, `voyage_number`, `container_number`, `port_of_loading`, `port_of_discharge`, `etd`, `eta`. |
| POST | `/supplier/orders/{reference}/tracking` | Update the same tracking fields without moving the status (at least one required). |
| POST | `/supplier/orders/{reference}/deliver` | `shipped` -> `delivered`. Optional `received_by`, `location`, `proof[]` (multipart, up to 5 PDF/JPG/PNG/WEBP). |
| POST | `/supplier/orders/{reference}/documents` | Attach shipping papers. Multipart: `documents[]` (1–10 PDF/JPG/PNG/WEBP), optional `kind` (`OrderDocumentKind`, default `other`), `label`. Buyer is notified. `throttle:api-upload`. |
| POST | `/supplier/orders/{reference}/payments` | Record an OFF-platform payment: `amount` (cumulative total received to date, >= 0), optional `method` (free-text name only — never account details). `409 order_action_not_allowed` when above the order total or the order is cancelled. |
| POST | `/supplier/orders/{reference}/cancel` | Cancel with a required `reason` (max 500). `409 order_transition_not_allowed` for completed/cancelled orders. |

**Pending verification — receive, but cannot respond.** Buyer requests are
routed (and the routing notification sent) to companies whose status is
`verified` **or** `pending`, but only a `verified` company may act on them.
While `can_respond` is `false`:

- `/supplier/rfqs*` and `/supplier/leads*` items return `buyer_name`,
  `buyer_company`, `buyer_email` (and on RFQs `notes`/`attachments`) as
  `null`/`[]` with `contact_locked: true`; the commercial spec is still shown.
- `POST /supplier/rfqs/{reference}/quote`, `POST /supplier/rfq-board/{reference}/express-interest`
  and `PATCH /supplier/leads/{id}` return `403` with error code
  `company_verification_required` and message "Complete your company
  verification to respond to buyer requests."
- `GET /auth/me` exposes `company.can_respond_to_buyers` so the app can show
  a "complete verification" banner and disable Quote/Express interest up
  front. The `rfq_routed` notification payload also carries `can_respond`.

Every supplier RFQ / board / lead item carries `can_respond` (bool) and
`contact_locked` (bool; always `true` on the board).

**Fulfilment and chat.** Accepting a quote does **not** create a conversation,
so an order may have `conversation_id: null` (e.g. accepted from the buyer's
e-mail link, or a guest buyer). The `/supplier/orders/{reference}/*` actions
above work either way and return the refreshed `SupplierOrderResource`
(`200`): when the order has a conversation they go through the same path as
`conversations/{id}/orders/{order}/*` (the buyer sees the card in the thread);
when it has none they go through the same path as the web exporter panel's
Orders table. Prefer these reference-based endpoints in the app; the
`conversations/{id}/orders/{order}/*` routes (and `actions[]` paths) remain for
in-thread UI and for the actions only available there (`proforma`,
`payment-request`). An illegal transition
is `409` `order_transition_not_allowed` (`order_action_not_allowed` for
`tracking`, `documents`, `payments`). Rate-limited (`throttle:api-decision`;
`documents` uses `throttle:api-upload`).

### Supplier — leads, capacities, lot transformations

| Method | Path | Purpose |
|---|---|---|
| GET | `/supplier/leads` | The lead pipeline of the caller's companies (same scope as the web "Leads" page), newest first, 15/page. Query: `status` (`new\|contacted\|won\|lost\|dormant`), `source` (`rfq\|inquiry\|manual`), `rfq_type` (`export\|domestic_manufacturing\|transport`). Item: `id`, `source`, `status {value,label}`, `buyer_name`, `buyer_email`, `buyer_country_code`, `value_amount`, `value_currency`, `notes` (private to the company), `rfq {reference_code,type,title}\|null`, `last_activity_at`, `created_at`. |
| GET | `/supplier/leads/{id}` | One lead (`404` if not yours). |
| PATCH | `/supplier/leads/{id}` | Any of `status` (any value above — no transition restrictions, same as the web form), `notes`, `value_amount`. Stamps `last_activity_at`. No create/delete (leads come from RFQs/inquiries). |
| GET / POST | `/supplier/capacities` | Declared capacities of the caller's company (any company type). POST body: `capability` (free text, max 150 — e.g. "Kiln drying", "Trucking — Douala corridor"), `quantity` (>= 0.01), `unit` (max 30, e.g. `m3`, `ton`, `TEU`), `period` (`day\|week\|month\|quarter\|year`) — all required. `201`. |
| GET / PATCH / DELETE | `/supplier/capacities/{id}` | One capacity; PATCH takes any subset of the fields above; DELETE `204`. `404` if not yours. |
| GET | `/supplier/lot-transformations` | Read-only mass-balance ledger where the caller's company is the PROCESSOR (written when a transformation job completes), newest `processed_at` first. Item: `id`, `transformation_type`, `input_volume_m3`, `output_volume_m3`, `loss_volume_m3`, `transformation_ratio`, `processed_at`, `notes`, `created_at`. |
| GET | `/supplier/lot-transformations/{id}` | Same plus `input_lots[]` / `output_lots[]` (`id`, `lot_number`, `quantity_m3`). |

### Company context — organisation type, plan & subscription

`GET /company`, `GET /auth/me` (`data.company`) and `GET /dashboard`
(`data.company`) all carry:

- `organisation_type`: `{ "value": "processor", "label": "Processor" }` (or `null`).
- `plan`: the plan whose features apply **right now** (`null` when none):
  `{ slug, name, segment, features: {...raw map, e.g. leads_receive, max_gallery, verified_badge, featured, api}, leads_receive: bool, max_gallery: int, is_free: bool, expires_at: ISO-8601|null }`.
  Gate UI on these (e.g. hide the Leads tab when `plan.leads_receive` is false).

`GET /company/subscription` (read-only, mirrors the web "Subscription" page):
`{ company_id, organisation_type, plan: {slug,name,segment,price_amount,price_currency,billing_period,features}|null, subscription: {id,status,starts_at,ends_at,renews_at,trial_ends_at,on_trial}|null, effective_plan: (as above), pricing_url }`.
Upgrading stays on the web (`pricing_url`).

**Artisan portfolio.** `PATCH /company` `gallery[]` items accept, besides
`image_path`/`caption`: `description` (max 2000), `is_portfolio` (bool),
`materials_used` (max 255), `completed_on` (date, not in the future). Items
with `is_portfolio: true` appear on the public `/companies/{slug}/portfolio`
page (artisan companies). `GET /company` returns the same fields per item.

### Fleet (logistics) — a company's own vehicles + drivers

All routes are under `/supplier/fleet/...`, behind the same `api.supplier`
gate as the rest of `supplier/*` (**🔑** = requires a company membership), and
**additionally require fleet eligibility**: the caller's `company.type` must
be `"logistics"`, or the account must hold the `logistics_partner` role —
the exact gate `Filament\Exporter\Resources\Vehicles\VehicleResource` /
`Drivers\DriverResource` use on the web (`currentUserManagesFleet()`). A
company member who is not fleet-eligible gets a hard **`403`** on every
route below, not an empty list — check `company.type` from `auth/me` before
showing the Fleet section in the client at all.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/supplier/fleet/vehicles` | 🔑 | The caller's company's vehicles, paginated (15/page). |
| POST | `/supplier/fleet/vehicles` | 🔑 | Create a vehicle. Body: `{ "registration_number": "...", "type": "truck\|trailer\|pickup\|van\|flatbed\|container_chassis\|other", "capacity_tonnes": 12.5, "is_active": true }` (`registration_number`, `type` required; `capacity_tonnes`/`is_active` optional). |
| GET | `/supplier/fleet/vehicles/{id}` | 🔑 | One vehicle. `404` if not the caller's company's. |
| PATCH | `/supplier/fleet/vehicles/{id}` | 🔑 | Partial update, same body fields, all optional. Rate-limited (`throttle:api-decision`). |
| GET | `/supplier/fleet/drivers` | 🔑 | The caller's company's drivers, paginated (15/page). |
| POST | `/supplier/fleet/drivers` | 🔑 | Create a driver. Body: `{ "name": "...", "license_number": "...", "phone": "...", "is_active": true }` (`name`, `license_number` required; `phone`/`is_active` optional). |
| GET | `/supplier/fleet/drivers/{id}` | 🔑 | One driver. `404` if not the caller's company's. |
| PATCH | `/supplier/fleet/drivers/{id}` | 🔑 | Partial update, same body fields, all optional. Rate-limited. |
| DELETE | `/supplier/fleet/vehicles/{id}` | 🔑 | Delete a vehicle (hard delete). `204`; `404` if not yours; **`409` `fleet_in_use`** while it is assigned to a shipment whose order is not yet delivered/completed/cancelled. |
| DELETE | `/supplier/fleet/drivers/{id}` | 🔑 | Delete a driver. Same rules as vehicles. |

Both resources ride the shared polymorphic Document store for compliance
paperwork (registration, insurance, driving licence, medical certificate) —
there is no separate expiry-tracking API yet; that rides the same
`/company/documents` family above.

### Logistics — shipments, waybills and checkpoints

A **shipment** is a trackable, waybill-bearing consignment of an order. It is
created explicitly (below, or the web "Create shipment / waybill" order
action) or **automatically when the order is marked shipped** if it has none
yet. The buyer sees it at `GET /orders/{reference}/shipments`.

Who sees/writes a shipment: members of its **carrier company**
(`carrier_company_id`, derived from the chosen vehicle/driver) **or** of the
order's **supplier company**. Anyone else gets `404`. All routes sit behind
`api.supplier` (any company member, including logistics companies).

**Carrier booking (`carrier_status`).** A supplier can give a third-party
logistics carrier a shipment in two ways, chosen with `"mode"` on create /
PATCH (default `assign`, so existing clients are unchanged):

| `carrier_status` | Meaning |
|---|---|
| `null` | Own fleet, or no carrier yet. |
| `assigned` | `mode: "assign"` — direct assignment; carrier notified, no action needed. |
| `pending` | `mode: "request"` — booking request; the carrier must accept or decline. |
| `accepted` | The carrier accepted the request. |
| `declined` | The carrier declined: carrier (and its vehicle/driver) cleared; `carrier_decline_reason` set. Re-book by PATCHing a carrier. |

A carrier member can see a `pending` shipment (to answer it) but can record
checkpoints only while `carrier_status` is `assigned`/`accepted` (`403
carrier_booking_not_active` otherwise; the supplier side can always record).
PATCH with the same carrier and `"mode": "assign"` turns a pending request into
a direct assignment.

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/supplier/orders/{reference}/shipments` | 🔑 | Supplier only (own sales order). Body (all optional): `{ "vehicle_id": 1, "driver_id": 2, "carrier_company_id": 3, "mode": "assign\|request", "origin": "Douala", "destination": "Le Havre" }`. Vehicle/driver/carrier must be the supplier's own or a logistics company's (`type = logistics`), active, and all from the same company — else `422`. `422 order_not_shippable` for delivered/completed/cancelled orders. Returns `201` + shipment. Rate-limited (`api-decision`). |
| GET | `/supplier/shipments` | 🔑 | Shipments visible to the caller (carrier or supplier), newest first, 15/page. |
| GET | `/supplier/shipments/{id}` | 🔑 | One shipment incl. `checkpoints[]` (ordered by `occurred_at`). |
| PATCH | `/supplier/shipments/{id}` | 🔑 | Edit assignment/route — e.g. give the bare shipment auto-created on ship a carrier. Body (any subset, `null` clears): `{ "vehicle_id", "driver_id", "carrier_company_id", "mode", "origin", "destination" }` (`mode` as on create). Only keys sent change; the resulting vehicle/driver/carrier set is validated exactly like creation (`422`). **Supplier side only:** a carrier member gets `403 not_shipment_supplier`, others `404`. Returns `200` + shipment. Rate-limited (`api-decision`). |
| POST | `/supplier/shipments/{id}/checkpoints` | 🔑 | Record a checkpoint. Body: `{ "status": "dispatched\|in_transit\|delayed\|delivered", "location": "...", "latitude": 4.05, "longitude": 9.7, "notes": "...", "occurred_at": "ISO-8601 device time", "client_event_id": "uuid" }` (`status` required). Optional proof photo: send as `multipart/form-data` with an image file in `photo` (max 5 MB, `422` otherwise); it is stored privately and exposed as `has_photo: true` + `photo_url` (never the storage path). A carrier on a `pending` booking gets `403 carrier_booking_not_active`. **Offline replay:** mint one `client_event_id` per captured checkpoint and resend it on every retry — a repeat returns `200` with `"replayed": true` and the original row instead of a duplicate (`201`, `"replayed": false`). Rate-limited (`api-decision`). |
| POST | `/supplier/shipments/{id}/accept` | 🔑 | **Carrier members only** (`403 not_shipment_carrier` for the supplier side): accept a `pending` booking request → `carrier_status: accepted`; supplier members notified. `409 booking_not_pending` if there is nothing to answer. Returns `200` + shipment. Rate-limited (`api-decision`). |
| POST | `/supplier/shipments/{id}/decline` | 🔑 | Carrier members only: decline a `pending` booking. Body: `{ "reason": "optional, max 500" }`. Clears the carrier (and its vehicle/driver), sets `carrier_status: declined`, notifies supplier members. After this the carrier no longer sees the shipment. Same errors as accept. |
| GET | `/supplier/shipments/{id}/checkpoints/{checkpoint}/photo` | 🔑 | Streams a checkpoint proof photo (`Content-Type` of the image, `Cache-Control: private`) to anyone who can see the shipment; `404` otherwise or without a photo. Linked as `photo_url`. |

Shipment shape: `id`, `waybill_number`, `order_reference`, `origin`,
`destination`, `carrier_company {id,name}`, `carrier_status`,
`carrier_status_label`, `carrier_decline_reason`, `carrier_responded_at`, `vehicle {id,registration_number}`,
`driver {id,name}`, `waybill_url` (public printable waybill + QR, always set),
`tracking_url` (public `/track/{token}` link — `null` until the first
checkpoint exists), `current_status` / `current_status_label` /
`current_status_updated_at`, `checkpoints[]` (show only: `id`,
`client_event_id`, `status`, `status_label`, `location`, `latitude`,
`longitude`, `notes`, `has_photo`, `photo_url`, `occurred_at`, `recorded_at`), `created_at`.

Notifications (mail + in-app `database`; type keys in parentheses):

- **Carrier assigned** (`shipment_assigned`, `screen: "shipment"`): when a
  supplier creates or edits a shipment so that a *third-party* logistics
  company becomes its carrier, every user of that company is told (deep link:
  the exporter Shipments view). Own-fleet assignments notify nobody. For a
  booking request (`mode: "request"`) the same type carries
  `booking_request: true` and "accept or decline" copy.
- **Booking accepted / declined** (`shipment_booking_accepted` /
  `shipment_booking_declined`, `screen: "shipment"`): the order's supplier
  company users when the carrier answers a request (declined includes
  `reason`).
- **Delivered** (`shipment_delivered`): a `delivered` checkpoint (API or web
  capture page) notifies the order's supplier company users (minus the
  recorder; `screen: "shipment"`) and the buyer ("goods delivered — please
  confirm", `screen: "order"`). The **order status is NOT changed** — the
  supplier still marks the order delivered and the buyer confirms as usual.
- **In transit** (`shipment_update`, `screen: "order"`): an `in_transit`
  checkpoint notifies the buyer at most once per shipment per 6 hours.

Web: the offline-capable capture page `/logistics/shipments/{waybill}/checkpoint`
now requires login (guests are redirected to login and back) and the same
carrier-or-supplier membership (carriers only once the booking is
assigned/accepted); the public waybill and `/track/{token}` pages stay open
(and never show checkpoint photos). Web pages that already authorised the
viewer (buyer order page, exporter shipment view) show photo thumbnails via a
30-minute temporary signed URL (`/shipments/{id}/checkpoints/{checkpoint}/photo`).

### Transformation — requests to processors, manufacturers & artisans

Read-only directory (public): `GET /transformation/providers`,
`GET /transformation/providers/{slug}`, `GET /transformation/match`. Eligible
providers are verified companies whose `type` is `processor`,
`manufacturer` or `artisan` (`OrganisationType::transformationProviders()`).

Request pipeline (authenticated, any company member):

| Method | Path | Purpose |
|---|---|---|
| GET | `/transformation/requests` | Paginated list. Query: `box` = `sent` (requests your company made) or `received` (requests made to your company). **Default:** `received` when your company's `type` is a provider type (processor/manufacturer/artisan), otherwise `sent`. `status` = `pending\|quoted\|accepted\|in_progress\|completed\|declined\|cancelled` (optional; unknown values are ignored). `per_page` 1–50 (default 15). |
| POST | `/transformation/requests` | Create. Body: `provider_slug`, `service` (`sawing\|drying\|planing\|moulding\|veneer\|other`), `volume_m3`, optional `species_slug`, `input_description`, `target_spec`, `deadline`, `notes`. |
| GET | `/transformation/requests/{reference}` | One request (`404` for non-participants). |
| POST | `.../{reference}/accept`, `/decline` (`reason`), `/quote` (`amount`, `currency` = `XAF\|EUR\|USD`, case-insensitive, `lead_time_days?`, `notes?`), `/start`, `/complete` (`input_volume_m3?`, `output_volume_m3?`, `notes?`) | Provider side. |
| POST | `.../{reference}/accept-quote`, `/decline-quote`, `/cancel` | Requester side. |

All `POST` routes above (create and every decision) share the `api-decision` rate limiter (`429` when exceeded), like the other decision endpoints.

Each item carries server-computed `actions[]` for the caller — render only those.

### Account deletion (App Store / Play requirement)

| Method | Path | Purpose |
|---|---|---|
| DELETE | `/auth/me` | Body: `password` (current). `204` on success. Throttled 5/min. |

Effects: every Sanctum token and Expo device token is revoked; the user row is
**kept but anonymised** (`name` → `Deleted user`, `email` →
`deleted+{id}@deleted.invalid`, `phone` null, 2FA cleared, random password) so
orders/quotes/messages keep their links; favorites/follows/roles removed;
company memberships detached. A company where the user was the **only member**
is archived (`status=archived`) together with its products. Logged to the
activity log as `account_deleted`.

Errors: `422` wrong/missing password (`error.details.password`); `403
staff_cannot_self_delete`; `409 transfer_ownership_first` when the user is the
sole owner of a company that still has other members.

Web: `GET /account/delete` (route `account.delete`, confirmation page for
settings pages to link to) and `POST /account/delete` (`password`, `confirm`)
do the same through `App\Actions\Account\DeleteAccount`.

### Referrals — "Refer & earn" and commission payouts

| Method | Path | Purpose |
|---|---|---|
| GET | `/referrals/me` | Code, share URL, stats, terms and `payout` (below). |
| GET | `/referrals` | People the user referred (masked names). |
| GET | `/referrals/earnings` | The user's commissions, newest first, with payout status. |
| PATCH | `/referrals/payout-settings` | Body: `paypal_payout_email` (email, or `null` / `""` to remove). Throttled 10/min. |

`payout` block (in `/referrals/me` and the PATCH response) — the email is **never** returned in full:

```json
{ "paypal_email_masked": "je*********@example.com", "has_paypal_email": true,
  "paypal_available": true, "paypal_currencies": ["USD", "EUR", "GBP"] }
```

`paypal_available: false` means PayPal payouts are not switched on yet (commissions are paid manually by MoMo / bank). Commissions in a currency outside `paypal_currencies` (e.g. XAF) are always paid manually. `422` with `error.details.paypal_payout_email` for an invalid email.

Each `/referrals/earnings` item adds to `id, source_reference, amount_label, status, status_label, at`:

| Field | Values |
|---|---|
| `payout_status` | `awaiting_approval` (commission not yet approved), `awaiting_payout`, `processing` (payout requested / sent to PayPal), `unclaimed` (PayPal holds it — the user must sign up / log in to PayPal with the payout email within 30 days), `failed` (check the payout email; it will be retried), `paid`, `cancelled` |
| `payout_status_label` | Display text for `payout_status`. |
| `payout_method` | `paypal` / `manual` once paid, else `null`. |
| `paid_at` | ISO-8601 or `null`. |

Notifications: `referral_payout_updated` (database + push, `screen: "referral"`, `payout_status`: `paid` / `failed` / `unclaimed`) and an email.

### Released-app compatibility notes

- **Supplier orders without a conversation**: `GET /supplier/orders/{ref}`
  `actions[]` is no longer empty for threadless orders — it carries the same
  `{key,label,method,path,fields?}` objects (`confirm`, `production`, `ship`,
  `deliver`, `tracking`) with `path` = `supplier/orders/{ref}/{action}`,
  plus `add_documents` (`POST supplier/orders/{ref}/documents`, multipart
  `documents[]` + optional `kind`/`label`) and `record_payment`
  (`POST supplier/orders/{ref}/payments`, `{amount, method?}`). `proforma`
  and `request_payment` appear only when a thread exists (threaded paths stay
  `conversations/{id}/orders/{order}/…`; each key appears at most once).
- **Supplier order `cancel` action**: emitted on any order (threaded or not)
  whose status may still move to `cancelled` (`awarded`, `confirmed`,
  `in_production`), always at `POST supplier/orders/{ref}/cancel` with a
  required `reason` field (≤500 chars). A **shipped** order can never be
  cancelled (`409 order_transition_not_allowed`); problems after shipping go
  through a dispute.
- **Notification deep links**: `company_verified` (company approved by staff)
  now also lands in the notification centre and as an Expo push (both gated
  by the user's notification preferences; email always goes) with
  `{type, title, body, company_id, screen: "verification"}`. Dispute
  notifications (`dispute_opened`, `dispute_reply`) now carry `dispute_id`
  next to `reference` (the order reference) so the app can open
  `/orders/{reference}/disputes/{dispute_id}` directly.
- **Product images**: `SupplierProductResource.images[]` =
  `[{id, url, alt, is_primary}]` — the primary image has `id: "primary"`,
  gallery (`product_images`) rows their numeric id.
  `DELETE /supplier/products/{product}/images/{id}` removes one (file deleted
  too; `404` for an id not on this product). `PATCH
  /supplier/products/{product}/images/order` body `{ids: [...]}` sets
  gallery `sort_order` (`"primary"` is ignored — it is always first; `422` for
  foreign ids). Removing the primary image of an active product is allowed.
- **`POST /conversations`** also accepts `company_slug` (alias of `company`)
  and `product_slug` (must belong to that company), and `body` is optional —
  without it the thread is started/reused and no message is posted.
- **`POST /rfqs`**: an unknown `items.N.species_slug` is dropped (not `422`)
  when the item also has `species_text` or `species_id`.
- **`GET /conversations/{id}/attachables?type=`** — `order|quote|rfq|receipt|shipment`
  (plural accepted). The caller's own records involving the thread's
  counterparty (buyer: orders/shipments/RFQs/receipts with that company;
  supplier: that buyer's orders/quotes/RFQs). Rows:
  `{type, id, reference, title, status, status_label, total_amount?, currency?, created_at}`
  (max 30, newest first). Kinds not applicable to the caller's side → `data: []`.
- **`GET /supply-chain/relationships?status=active|pending`** — read-only,
  derived from **completed orders** only: `supplier` (a company you bought
  from) and `customer` (a company whose member bought from your company).
  Rows: `{id, relationship, status:"active", direction:null, source, orders_count, last_order_at, company:{id,slug,name,type}, actions:[]}`.
  `pending` is always empty; `POST` (invitations) is **coming soon** — not
  routed (`405`), which the app renders as "coming soon".
- **Demo logins** (`/auth/demo-personas`, `/auth/demo-login/{persona}`) are
  gated by `DEMO_LOGINS_ENABLED` (Pennant `DemoLoginsEnabled`): when off,
  personas returns `200 {data: []}` and login `403`.

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


### Carrier picker — `GET /api/v1/supplier/carriers?q=`

Lists logistics companies a supplier can choose as a shipment carrier (verified first, then pending; suspended/rejected/archived excluded; the caller's own company excluded). `q` matches legal name, trade name or city. Response: `{data:[{id, name, city, region, verified}], meta:{current_page,last_page,total}}`, 20 per page. Pass the chosen `id` as `carrier_company_id` with `mode: assign|request` to `POST supplier/orders/{reference}/shipments` or `PATCH supplier/shipments/{id}`.
