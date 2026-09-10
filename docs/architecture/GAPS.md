# Architecture / API — known gaps

Surfaced by the 2026-09-10 architecture-doc scan. These are **tracked, not
forgotten** — none is a blocker for what ships today, but each is a rough edge
a future phase should close. Ordered roughly by cheapness to fix.

## 1. Webhook subscribable list is stale

`App\Jobs\RelayOutboxEventsJob::EVENT_MAP` delivers **14** event types.
`App\Filament\Exporter\Resources\WebhookSubscriptions\Schemas\WebhookSubscriptionForm::EVENT_TYPES`
still lists only **3** (`order.awarded`, `checkpoint.recorded`,
`compliance_case.opened`). The other 11 are deliverable but not subscribable
via the exporter UI. Its doc block claims it is "kept in sync by hand" — it is
not. **Fix:** derive both from one canonical source (a list constant / enum),
or at minimum bring `EVENT_TYPES` up to 14. Deferred here because the
webhook-hardening pass is already touching this area.

## ~~2. No automated bounded-context boundary enforcement~~ — CLOSED

See [Closed](#closed) below.

## 3. Query coverage is asymmetric — CLOSED (with noted exceptions)

**Closed 2026-09-10.** Named `Query` classes now cover the genuinely-heavy or
API-relevant reads in the three under-covered contexts, mirroring the Trade
pattern (thin Query DTO, Handler delegates to the existing service/scope,
caller dispatches via `QueryBus`):

- **Catalog** — `SearchProductCatalogueQuery` (delegates to
  `ProductCatalogueService::search`; wired into
  `Api\V1\ProductController::index`), `ListSupplierProductsQuery` (reproduces
  the exporter `ProductResource::getEloquentQuery` company-scope; wired there).
- **Identity & Access** — `ListVerifiedSuppliersQuery` (delegates to
  `SearchService::companyQuery`; wired into `Api\V1\SupplierController::index`),
  `ListPendingVerificationsQuery` (reproduces the `PendingVerificationsWidget`
  query closure; wired there).
- **Commerce & Billing** — `GetCompanySubscriptionQuery` (reproduces
  `SubscriptionStatus::getActiveSubscription`'s `subscriptions()->active()
  ->latest()->first()`; wired there).

Each wired caller produces byte-identical output; see
`tests/Feature/Domain/QueryCoverageTest.php` and the unchanged route/page
tests.

**Deliberately left inline (not worth a Query):**

- `ListCompanyPaymentsQuery` was in scope but **not built**: there is no
  company-scoped payment-history read anywhere in the codebase today. `Payment`
  has no `Company` `hasMany`, and every existing `Payment` read is a
  gateway-callback lookup by `provider_reference` (`StripeGateway`,
  `PayPalGateway`, `OrangeMoneyGateway`, `MtnMomoGateway`) or a
  `PaymentCheckoutController::create`. A Query here would have no caller to
  rewire and no existing scope/service to delegate to — it would be net-new
  query logic, which the "thin seam, don't reimplement" rule forbids. Add it
  alongside the first real billing-history view or billing API endpoint.
- `Api\V1\ProductController::show`, `SupplierController::show`,
  `SpeciesController::show` — single-record `where('slug', …)->firstOrFail()`
  reads with a one-line visibility `whereHas`; no multi-condition scoping,
  pagination or reused eager-load set worth naming.
- `SpeciesController::index` / `SearchController` already read entirely through
  `SpeciesDirectoryService` / `SearchService`; the service *is* the shared
  seam. Wrapping them adds a DTO with no second caller. Revisit if/when a
  web+API split emerges.
- The public web catalogue/directory Livewire components were not rewired —
  they call the same services; the API controllers were the stated target.

## 4. ~~Error envelope not standardised in code~~ — CLOSED

~~All current `Api/V1` controllers return `{message}` (or `{message, errors}`
for 422) — consistent, but minimal: no machine-readable `code`, no
`request_id`.~~

**Closed 2026-09-10.** Every `api/*` error now leaves as one envelope —
`{ "error": { "code", "message", "request_id", "details"? } }` — shaped in a
single place, `App\Exceptions\Api\ErrorEnvelope`, wired from
`bootstrap/app.php`'s `withExceptions()->render()`. Controllers that used to
hand-roll `response()->json(['message' => …], 409)` now throw
`App\Exceptions\Api\ConflictException` / `ApiException`. 422 keeps its field
map under `error.details`. See [`../api/CONVENTIONS.md`](../api/CONVENTIONS.md)
§"Error responses".

## 5. Deprecation-header mechanism not implemented — **CLOSED**

~~`/api/v1` is additive-only and a 6-month `Deprecation`/`Sunset` notice policy
is proposed, but there is **no middleware** that emits those headers.~~

**Closed:** `App\Http\Middleware\AnnounceDeprecation` (alias `deprecated`,
registered in `bootstrap/app.php`) emits RFC 8594 `Deprecation` / `Sunset` /
`Link rel="successor-version"` / `Warning` headers on any route or group it is
applied to, parsing its params defensively. Nothing is deprecated today, so it
is applied to no route; a commented example declaration sits above the `v1`
group in `routes/api.php`, and the "how to sunset an endpoint" flow is in
`docs/api/CONVENTIONS.md`. Test-covered by
`tests/Feature/Api/DeprecationHeaderTest.php`. There is still no `/api/v2`.

## 6. ~~No request-id correlation~~ — CLOSED

~~No `X-Request-Id` is accepted or emitted; error bodies and the activity log
carry no correlation id.~~

**Closed 2026-09-10** (same branch as §4 — the two pair naturally).
`App\Http\Middleware\AssignRequestId` (prepended to the `web` and `api`
middleware groups in `bootstrap/app.php`, and on the `api/v1` route group)
accepts an inbound `X-Request-Id` only if it is a well-formed ULID or UUID —
anything else is ignored and a fresh ULID minted — stores it on the request
attribute bag and in Laravel's `Context`, and echoes it as the `X-Request-Id`
response header. It appears in every `api/*` error body as `error.request_id`
and is stamped into the activity-log `properties` next to the existing `ip` /
`user_agent` (the stamper was also fixed to bind to the configured
`ChainedActivity` model, not the base `Activity` class — Eloquent keys model
events by concrete class, so the old registration never fired).
Test-covered by `tests/Feature/Api/RequestIdAndErrorEnvelopeTest.php`.

## 7. API-key rate-limit tiers not wired to Plans

`throttle:api-key` reads `ApiKeyMeta.rate_limit_tier` (`basic`/`standard`/
`elevated`), defaulting everything to `standard`. The blueprint's intent —
"a company's Plan determines its API rate limit and which scopes it can
request" — is **Phase 3** and not built. Tiers are currently set manually at
issuance, decoupled from `plans`/`subscriptions`.

## 8. Webhook signature scheme is pre-GA

`WebhookDeliveryService::sign()` HMACs `json_encode($payload)` with the stored
SHA-256 hash of the secret as the key. No envelope, no event id / timestamp in
the signed material, no replay protection, and the signed bytes are not
guaranteed byte-identical to what the consumer receives. A hardening pass
(envelope + exact-bytes signing + encrypted secret + replay window) is in
progress. Documented for consumers in
[`../api/WEBHOOKS.md`](../api/WEBHOOKS.md) with a "subject to change" banner.

---

## Closed

### 2. No automated bounded-context boundary enforcement — closed 2026-09-10

(commit: "Close arch gap 2: enforce bounded-context boundaries via Pest arch() test")

`tests/Architecture/BoundedContextTest.php` now enforces, via Pest `arch()`
plus a reflection sweep (see [`README.md`](README.md) §1 → "Enforcement"):

- no `App\Domain\{Context}` namespace may `use` another context's
  `App\Domain\*` namespace (6 contexts: Trade, Logistics, Compliance,
  Identity, Commerce, Catalog). Shared `App\Models\*` access stays allowed,
  per the strangler-fig decision.
- CQRS/event contract + naming conventions: `*Command`/`*Query` and their
  `*Handler`s, and every `Events\*` class, implement the matching
  `App\Support\*` interface.

**No real cross-context violation existed at closure** — every existing
`use App\Domain\…` under `app/Domain/` resolved within its own context, so the
test is green with zero documented exceptions.
