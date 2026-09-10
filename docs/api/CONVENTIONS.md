# CTH API — contract & conventions

Cross-cutting rules for `/api/v1`. Endpoint-level reference is the generated
OpenAPI spec at **`GET /docs/api`** (see [`README.md`](README.md)); the
TypeScript client lives in **`sdks/typescript/`**.

Where a rule below is a **proposal** (not yet ratified or implemented) it is
marked as such.

---

## Versioning

- The version is a URL prefix: `/api/v1`. Route names are `api.v1.*`.
- **`/api/v1` is additive-only.** New optional request fields, new response
  fields and new endpoints are allowed. Removing/renaming a field, changing a
  type, tightening validation, or changing status-code semantics are breaking
  changes and are **not** allowed in v1.
- A breaking change ships as **`/api/v2`**, running alongside v1.

### Deprecation policy

**Policy (unchanged, still the stated figure):** minimum **6 months' notice**
between the first `Sunset` header and actual removal of a v1 endpoint or of
v1 as a whole. Nothing is deprecated today.

**Mechanism (available):** the `deprecated` middleware alias
(`App\Http\Middleware\AnnounceDeprecation`, registered in `bootstrap/app.php`)
emits RFC 8594 / draft-ietf-httpapi-deprecation-header signalling on any route
or group it is applied to. It is **not applied to any route** — see the
commented example block above the `v1` group in `routes/api.php`.

Declared as:

```php
->middleware('deprecated:<deprecation-date>,<sunset-date>,<successor-url>,<note>')
```

All four params optional, parsed defensively (a missing/malformed part never
500s — it degrades). Headers emitted on the response:

| Header | Value | When |
|---|---|---|
| `Deprecation` | IMF-fixdate, e.g. `Fri, 01 Jan 2027 00:00:00 GMT` | always; `true` if the date is missing/unparseable |
| `Sunset` | IMF-fixdate | when a parseable sunset date is given |
| `Link` | `<successor-url>; rel="successor-version"` | when a successor URL is given |
| `Warning` | `299 - "<note>"` | when a note is given |

Dates accept anything Carbon parses (e.g. `2027-01-01`); output is always
HTTP-date in UTC.

**How to sunset an endpoint:**

1. Ship the replacement (`/api/v2/...`) alongside v1.
2. Add the middleware to the v1 route/group with the deprecation date (today),
   a sunset date **≥ 6 months out**, and the v2 successor URL, e.g.
   `->middleware('deprecated:2027-01-01,2027-07-01,https://www.cameroontimberhub.com/api/v2/products/{slug},Use /api/v2/products/{slug}')`.
3. Announce in the API changelog / migration note; notify key holders.
4. After the sunset date has passed, remove the route and the middleware.

---

## Authentication

- **Sanctum bearer tokens.** `Authorization: Bearer <token>`. Public catalogue
  routes (`products`, `species`, `suppliers`, `search`) need no token; buyer
  routes are `auth:sanctum` + `api.buyer` (`EnsureApiBuyer` — a signed-in
  `User` who is neither platform staff nor a member of a supplier company;
  answers `401` unauthenticated, `403` for staff/supplier tokens).
- **Mobile app tokens** are user-issued at `POST /api/v1/auth/register` and
  `POST /api/v1/auth/login` (the same `RegisterAccount` action the web form
  uses). Login failure is one generic message on the `email` field for every
  cause — the endpoint is deliberately not an account-existence oracle.
- **Company API keys** are a distinct product surface. Issuance is a
  **two-person + step-up-2FA** control (`app/Actions/ApiKeys/`), mirroring the
  AI-provider-key flow:
  1. `RequestApiKeyIssuance` — an admin proposes a named key with a set of
     Sanctum abilities for a company; a one-time invite token is generated
     (plaintext returned once, relayed out-of-band).
  2. `ApproveApiKeyIssuance` — a **different** admin supplies that invite
     token **and** a recent confirmed TOTP code (`TwoFactorStepUp`). Only then
     is a real Sanctum personal access token minted with the requested
     abilities, scoped to the company via `ApiKeyMeta`. Plaintext is shown
     once.
  Managed from the admin panel (`ApiKeyIssuanceRequests` resource), gated on
  `api-keys.manage`.
- **Scoped abilities** — Sanctum abilities, e.g. `products:read`,
  `rfqs:write`. A request succeeds only for abilities its token holds.
- **Per-key rate limits tied to Plan tier** — see below. *(Tier→Plan wiring is
  a target; today all keys resolve a `standard`/`ApiKeyMeta.rate_limit_tier`
  value, not a Plan. See GAPS.md.)*

---

## Rate limiting

Two layers, both via Laravel's `throttle:` middleware:

- **Per-endpoint** limiters (`throttle:api-login`, `api-register`, `api-rfq`,
  `api-rfq-verify`, `api-decision`) — keyed per-IP and/or per-user/-email, e.g.
  login is 5/min/IP + 5/min/email + 30/hour/IP; a quote decision is 10/min +
  120/hour per user.
- **Per-API-key** (`throttle:api-key`, applied to the whole `v1` group) — keyed
  by the Sanctum **token id** (falls back to IP when unauthenticated), so two
  keys for the same user get independent budgets. Limit by
  `ApiKeyMeta.rate_limit_tier`: `basic` 30/min, `standard` 60/min, `elevated`
  300/min; unauthenticated 60/min/IP.

**Rate-limit headers** (added by Laravel's throttle middleware):
`X-RateLimit-Limit`, `X-RateLimit-Remaining`; on a `429`, additionally
`Retry-After` and `X-RateLimit-Reset`.

---

## Error responses

Standard HTTP status codes. Observed shapes in `App\Http\Controllers\Api\V1\*`:

| Status | Meaning | Body shape |
|---|---|---|
| `401` | No / invalid token | `{"message": "Unauthenticated."}` (Laravel default) |
| `403` | Token's population or abilities disallow this endpoint | `{"message": "This endpoint is for buyer accounts."}` |
| `404` | Not found, **or** a resource owned by another buyer (deliberate — a reference-grinder learns nothing) | `{"message": "..."}` |
| `409` | Legal-but-not-now: quote already settled/expired, illegal state-machine move (`QuoteController::accept/decline`, Trade Assurance, disputes) | `{"message": "This quote is accepted and can no longer be actioned."}` |
| `422` | Validation failure (FormRequest) | `{"message": "...", "errors": {"<field>": ["..."]}}` |
| `429` | Rate limited | `{"message": "Too Many Attempts."}` + `Retry-After` |
| `500`-class business failure surfaced deliberately | e.g. `RfqController::store` risk rejection | `{"message": "Your request could not be submitted."}` |

So today: **`{message}`** for everything except **`{message, errors}`** for
422. This is consistent across the current controllers.

### Proposal — one documented envelope

The current state is *consistent* but *minimal* (no machine-readable `code`, no
correlation id in the body). A future standard envelope could be:

```json
{ "message": "human string",
  "code": "quote_not_actionable",
  "errors": { "field": ["..."] },
  "request_id": "..." }
```

**Not implemented.** If adopted, `422` keeps its `errors` map; other errors
gain `code`. No current endpoint deviates from the `{message}` / `{message,
errors}` pair, so this is a pure addition, not a cleanup. Tracked in GAPS.md.

---

## Pagination

List endpoints (`orders`, `rfqs`, quote/dispute collections) return Laravel
paginated resource collections: a `data` array plus `links` (`first`, `last`,
`prev`, `next`) and `meta` (`current_page`, `last_page`, `per_page`, `total`).
Page size is fixed per endpoint server-side (e.g. orders 15/page); pass `?page=N`.

## Request correlation

**Not implemented.** There is no `X-Request-Id` accepted or emitted, and no
request id in error bodies or logs today. Adding one (accept-or-generate, echo
in the response header, stamp into the activity log's `properties` alongside
the IP/UA already captured) is a proposal — see GAPS.md.

## Success response shape

Single resource: `{ "data": { ... } }` (an API Resource). Actions that produce
more than one entity return a keyed object under `data`, e.g.
`QuoteController::accept` → `{ "data": { "quote": {...}, "order": {...}|null } }`.
