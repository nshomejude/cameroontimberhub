# Architecture / API — known gaps

Surfaced by the 2026-09-10 architecture-doc scan. These were **tracked, not
forgotten** — none was a blocker for what shipped, each was a rough edge a
future phase should close.

**Open gaps: 0.** Every gap below has been closed; see [Closed](#closed).

---

## Closed

### 1. Webhook subscribable list is stale — closed 2026-09-10

(commit: 17664a0)

`App\Jobs\RelayOutboxEventsJob::subscribableEventTypes()` is now the single
canonical registry of deliverable event types, and
`App\Filament\Exporter\Resources\WebhookSubscriptions\Schemas\WebhookSubscriptionForm::eventTypeOptions()`
derives its checkbox list from it (`collect(RelayOutboxEventsJob::subscribableEventTypes())
->mapWithKeys(...)`), with labels generated from the event-type string. There
is no longer a hand-maintained second copy that can drift.

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

### 3. Query coverage is asymmetric — closed 2026-09-10 (with noted exceptions)

Named `Query` classes now cover the genuinely-heavy or API-relevant reads in
the three under-covered contexts, mirroring the Trade pattern (thin Query DTO,
Handler delegates to the existing service/scope, caller dispatches via
`QueryBus`):

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

### 4. Error envelope not standardised in code — closed 2026-09-10

Every `api/*` error now leaves as one envelope —
`{ "error": { "code", "message", "request_id", "details"? } }` — shaped in a
single place, `App\Exceptions\Api\ErrorEnvelope`, wired from
`bootstrap/app.php`'s `withExceptions()->render()`. Controllers that used to
hand-roll `response()->json(['message' => …], 409)` now throw
`App\Exceptions\Api\ConflictException` / `ApiException`. 422 keeps its field
map under `error.details`. See [`../api/CONVENTIONS.md`](../api/CONVENTIONS.md)
§"Error responses".

### 5. Deprecation-header mechanism not implemented — closed 2026-09-10

`App\Http\Middleware\AnnounceDeprecation` (alias `deprecated`, registered in
`bootstrap/app.php`) emits RFC 8594 `Deprecation` / `Sunset` /
`Link rel="successor-version"` / `Warning` headers on any route or group it is
applied to, parsing its params defensively. Nothing is deprecated today, so it
is applied to no route; a commented example declaration sits above the `v1`
group in `routes/api.php`, and the "how to sunset an endpoint" flow is in
`docs/api/CONVENTIONS.md`. Test-covered by
`tests/Feature/Api/DeprecationHeaderTest.php`. There is still no `/api/v2`.

### 6. No request-id correlation — closed 2026-09-10

(same branch as §4 — the two pair naturally.)
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

### 7. API-key rate-limit tiers not wired to Plans — closed 2026-09-10

(commit: this change)

The `throttle:api-key` limiter (`App\Providers\AppServiceProvider`) now
resolves a token's tier with the same precedence the issuance path
(`App\Actions\ApiKeys\ApproveApiKeyIssuance`) already used, so runtime and
issuance never diverge:

1. the owning company's **current active plan**'s `Plan::apiRateLimitTier()`
   — company resolved from the token's `ApiKeyMeta`, active plan via
   `Company::activeSubscription`;
2. the explicit `ApiKeyMeta.rate_limit_tier` when the token has a companion
   row but no resolvable plan (manually-tiered partner keys with no
   subscription);
3. `config('api.rate_limit_tiers.default')` (`basic`) otherwise — this
   replaced the old hardcoded `'standard'` fallback;
4. unauthenticated (no token) requests are unchanged at 60/min/IP.

The tier→per-minute map (`basic` 30, `standard` 60, `elevated` 300) is
unchanged. Resolution is wrapped in a try/catch that logs and falls through to
the config default on any error — a limiter closure that throws would 500
every API request — and the resolved tier is memoised per token id for the
process lifetime. Test-covered by
`tests/Feature/ApiKeyRateLimitTierRuntimeTest.php` (plan tier beats a lower
meta tier; meta tier used when there is no subscription; config default when
there is neither) plus the existing
`tests/Feature/ApiKeyAbilitiesAndRateLimitTest.php` and
`tests/Feature/ApiKeyRateLimitTierFromPlanTest.php`.

### 8. Webhook signature scheme is pre-GA — closed 2026-09-10

(commit: fc0e5ac — "Harden webhook delivery to Stripe/GitHub conventions")

`App\Services\Webhooks\WebhookDeliveryService` now signs a standard event
envelope: `id` (`evt_` + stable per-delivery ULID `event_id`, identical across
retries), `type`, `created`, `data`. The envelope is JSON-encoded exactly once
with a fixed flag set and **that exact string** is both signed and sent.
Signature is HMAC-SHA256 over `"<timestamp>.<body>"` keyed by the
subscription's **plaintext `secret`** (stored encrypted at rest — not a
`secret_hash`), sent as `X-CTH-Signature: t=<ts>,v1=<sig>` with companion
`X-CTH-Timestamp` / `X-CTH-Event` / `X-CTH-Delivery` headers; consumers reject
a timestamp more than 5 minutes out (replay window). Documented for consumers
in [`../api/WEBHOOKS.md`](../api/WEBHOOKS.md), which no longer carries a
"subject to change" banner — the scheme is stable within v1.
