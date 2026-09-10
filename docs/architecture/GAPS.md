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

## 2. No automated bounded-context boundary enforcement

The 8-context ownership map in [`README.md`](README.md) §1 is convention only.
Nothing stops a `Trade` class from importing a `Compliance` model directly.
The blueprint anticipates "Deptrac or a simple custom Composer script … added
per-context once it's done" — not yet added for any context. **Fix:** a
Deptrac ruleset (or a Pest architecture test) per migrated context.

## 3. Query coverage is asymmetric

Trade, Logistics and Compliance have named `Query` classes over their heavy
reads. **Identity & Access, Catalog and Commerce & Billing** reads still go
straight through Eloquent from controllers/services — the "heavy reads become
named Queries" target is only ~half met. Not urgent (those contexts have
thinner read surfaces), but it means the CQRS seam the API depends on is not
uniform.

## 4. Error envelope not standardised in code

All current `Api/V1` controllers return `{message}` (or `{message, errors}`
for 422) — consistent, but minimal: no machine-readable `code`, no
`request_id`. [`../api/CONVENTIONS.md`](../api/CONVENTIONS.md) proposes a
single documented envelope. **Not implemented.** Pure addition when adopted
(no endpoint currently deviates).

## 5. Deprecation-header mechanism not implemented

`/api/v1` is additive-only and a 6-month `Deprecation`/`Sunset` notice policy
is proposed, but there is **no middleware** that emits those headers, and no
`/api/v2`. Needs building before the first v1 breaking change is contemplated.

## 6. No request-id correlation

No `X-Request-Id` is accepted or emitted; error bodies and the activity log
carry no correlation id (the activity log does already stamp IP + user agent
into `properties`). Adding accept-or-generate + echo + log-stamp is a small,
isolated improvement.

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
