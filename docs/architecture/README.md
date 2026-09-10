# Cameroon Timber Hub — Architecture & Conventions

Living reference for how CTH is organised internally. It describes **what the
code actually does today**, not an end-state. Where something is a target or a
proposal it is labelled as such.

Source blueprint: [`docs/superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md`](../superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md).
This is a **strangler-fig migration** — every Blade/Livewire/Filament screen
keeps working at every commit; contexts are refactored in place, one phase at a
time, never in a big-bang restructure.

---

## 1. Bounded contexts

CTH is organised around eight bounded contexts. A context talks to another
context **only** via a domain event or an explicit application-service call —
never by reaching into another context's Eloquent models directly. The
boundary is a convention today; automated enforcement (Deptrac or similar) is a
known gap (see [`GAPS.md`](GAPS.md)).

| Context | Responsibility | Owns (Eloquent models) |
|---|---|---|
| **Identity & Access** | Who can act on the platform and what they are trusted to do. Registration, company membership and roles, the 8-stage verification workflow, verification badges, and step-up (TOTP) re-authentication for sensitive actions. | `User`, `Company`, roles/permissions (Spatie), `Verification`, `VerificationCheckpoint`, `VerificationRequest`, `VerificationBadge` |
| **Catalog** | The tradeable inventory surface: what is listed, described and discoverable. Product listings and their lifecycle to "published/active", species reference data, company production capacity, and carbon project profiles. | `Product`, `Species`, `CompanySpecies`, `Capacity`, `CarbonProject` |
| **Trade** | The commercial core: turning demand into a settled order. RFQ intake and matching, quotes and their state machine, order creation from an accepted quote, order lifecycle (awarded → shipped → delivered), and Trade Assurance milestone coordination. | `Rfq`, `RfqItem`, `RfqCompany`, `Quote`, `QuoteItem`, `Order`, `OrderItem`, `TradeAssuranceAgreement`, `TradeAssuranceMilestone` |
| **Logistics & Traceability** | Moving goods and proving chain-of-custody. Shipments and digital waybills, checkpoint/tracking updates, and the timber-lot ledger (lot events, mass-balance transformations). | `Shipment`, `CheckpointUpdate`, `TimberLot`, `LotEvent`, `LotTransformation`, `Inventory`, `Vehicle`, `Driver` |
| **Compliance & Trust** | Keeping the marketplace legal and honest. Compliance rules and cases, regulatory sources, physical inspections, dispute resolution, and risk / fraud signalling. | `ComplianceRule`, `ComplianceCase`, `RegulatorySource`, `Inspection`, `Inspector`, `Dispute`, `DisputeEvidence`, `DisputeMessage`, `RiskAssessment`, `FraudSignal` |
| **Commerce & Billing** | What a company is entitled to and what it has paid. Plans, subscriptions, payments across the four gateways, AI-provider settings, and (target) API-key usage metering / rate-limit tiers. | `Plan`, `Subscription`, `Payment`, `AiSetting`, `ApiKeyMeta`, `ApiKeyIssuanceRequest`, `ApiKeyUsageDaily` |
| **Intelligence** | Aggregated, denormalised read models over everyone else's data. Market-intelligence snapshots, platform KPI / North-Star tracking, the compliance assistant, and document extraction. | `MarketIntelligenceSnapshot`, `PlatformKpiSnapshot`, `ComplianceAssistantQuery`, `DocumentExtraction` |
| **Platform** | Cross-cutting infrastructure every context leans on. Hash-chained audit log, notifications, feature flags, the transactional outbox, webhook subscriptions and deliveries. | `OutboxEvent`, `WebhookSubscription`, `WebhookDelivery`, `ChainedActivity` (activity log), notifications, Pennant feature flags |

### Enforcement

`tests/Architecture/BoundedContextTest.php` (Pest `arch()` + a reflection
sweep) makes two of the rules above structural, so CI fails on a regression:

- **No cross-context `Domain` imports.** For each of the six contexts with an
  `app/Domain/{Context}/` subtree (Trade, Logistics, Compliance, Identity,
  Commerce, Catalog), that namespace may not `use` any other context's
  `App\Domain\*` namespace. Shared `App\Models\*` access is **not** forbidden —
  that matches the strangler-fig decision below. **Documented exceptions:**
  none — every `use App\Domain\…` under `app/Domain/` currently resolves within
  its own context.
- **CQRS / event contracts.** `*Command` → `App\Support\Bus\Command`,
  `*Handler` in `Commands/` → `HandlesCommand`, `*Query` →
  `App\Support\Bus\Query`, `*Handler` in `Queries/` → `HandlesQuery`, every
  class in `Events/` → `App\Support\Events\DomainEvent`.

Run: `php artisan test tests/Architecture`.

### Models stay in `app/Models/` — deliberately

New developers frequently expect `app/Domain/{Context}/Models/`. **That is not
how this codebase is laid out, and that is on purpose.**

- Eloquent models remain in `app/Models/` (flat). Only the **behavioural** DDD
  artefacts — Commands, Queries, Events, and their handlers — live under
  `app/Domain/{Context}/`.
- Reason: strangler-fig migration. Moving ~90 model files into context
  namespaces is a big-bang refactor that touches every import in the codebase,
  risks the 1,300+ passing tests, and delivers no behavioural value. The
  bounded-context boundary is expressed through **which Command/Query/Event
  namespace** owns a write, not through the model's file path.
- A model is "owned" by the context whose `app/Domain/{Context}/` classes
  mutate it. The table in §1 is the authoritative ownership map.

---

## 2. CQRS conventions

Pragmatic CQRS: **code organisation and a stable seam for the API**, not
infrastructure duplication. No separate read database, no event sourcing.
Eloquent + PostgreSQL stays the single source of truth.

### Buses

`app/Support/Bus/CommandBus.php` and `app/Support/Bus/QueryBus.php` are thin,
container-resolved dispatchers. **Handler-resolution convention (verbatim from
`CommandBus`'s doc block — do not invent a second convention):**

> Command class name with its trailing "Command" stripped (if present) plus a
> "Handler" suffix, in the SAME namespace as the command.
>
> e.g. `App\Domain\Trade\Commands\AwardQuoteCommand`
>   -> `App\Domain\Trade\Commands\AwardQuoteHandler`
>
> The handler is resolved via the Laravel container, so its constructor may
> type-hint any dependency (existing Services included) for normal DI. The
> whole `handle()` call is wrapped in `DB::transaction()` — a handler that
> throws rolls back everything it did, including anything it wrote before the
> exception.

`QueryBus` uses the identical rule (strip trailing `Query`, add `Handler`, same
namespace) but is **not** wrapped in a transaction — reads don't need one.

Handlers must implement `App\Support\Bus\HandlesCommand` / `HandlesQuery` or the
bus throws.

### Command vs. service call

- **Write a Command** when the write is a domain use case with intent worth
  naming: it changes aggregate state, may emit domain events, needs the
  transaction + (future) authorization/validation envelope, and should be
  reachable identically from web, API and jobs. Examples today:
  `AwardQuoteCommand`, `RecordOrderShipmentCommand`, `OpenDisputeCommand`,
  `FinaliseInspectionCommand`, `RecordCheckpointCommand`,
  `PublishProductCommand`, `ApproveVerificationCommand`.
- **Call a service directly** for reads-with-side-effects that aren't a domain
  transition (e.g. `QuoteService::markViewed()`), for pure orchestration, and
  inside a handler (handlers routinely delegate to the existing `App\Services\*`
  class rather than duplicating its logic — the Command is a typed seam over
  the service, not a rewrite of it).
- **Target:** every context write goes through a named Command. Not yet true —
  only migrated contexts (Trade, Logistics, Compliance, plus Phase-4 slices of
  Catalog / Identity / Commerce) route writes this way today.

### Query conventions

- Dedicated read-model classes under `app/Domain/{Context}/Queries/`, resolved
  through `QueryBus`. Most stay backed by the existing Eloquent models — no
  premature read/write split.
- Only genuinely expensive aggregate views (Market Intelligence, Platform
  Operations) get a real denormalised projection table, populated by
  outbox-event listeners.
- **Target:** heavy reads become named Queries. Coverage per context:
  - **Trade** — `ListBuyerOrdersQuery`, `ListBuyerQuotesQuery`,
    `ListBuyerRfqsQuery`, `ListBuyerReceiptsQuery`
  - **Logistics** — `GetOrderShipmentTrackingQuery`
  - **Compliance** — `ListOrderDisputesQuery`
  - **Catalog** — `SearchProductCatalogueQuery` (public marketplace
    search/filter, backs `Api\V1\ProductController::index`),
    `ListSupplierProductsQuery` (a supplier's own company-scoped listings,
    backs the exporter `ProductResource`)
  - **Identity & Access** — `ListVerifiedSuppliersQuery` (public supplier
    directory, backs `Api\V1\SupplierController::index`),
    `ListPendingVerificationsQuery` (admin verification-review queue, backs
    `PendingVerificationsWidget`)
  - **Commerce & Billing** — `GetCompanySubscriptionQuery` (a company's
    current active subscription, backs the exporter `SubscriptionStatus`
    page)

  Trivial `Model::find($id)` reads with no scoping logic are deliberately
  left inline. See [`GAPS.md`](GAPS.md) gap 3 for the reads judged not worth
  a Query.

---

## 3. Event-driven backbone

Today's Eloquent Observers are informal, synchronous, untyped domain-event
reactions. The backbone makes them **typed, asynchronous and replayable**, and
makes the same events available to external subscribers as webhooks.

### Transactional outbox

- `outbox_events` table (`id`, `aggregate_type`, `aggregate_id`, `event_type`,
  `payload` jsonb, `occurred_at`, `published_at` nullable, `attempts`), model
  `App\Models\OutboxEvent`.
- A domain event is recorded **inside the same DB transaction as the state
  change** via the `App\Support\Events\RecordsOutboxEvents` trait
  (`recordOutboxEvent(DomainEvent $event)`). The trait does **not** open its
  own transaction — it relies on the caller already being inside one (a
  `CommandBus` handler always is; an Observer `created` callback fires while
  the owning service's `DB::transaction()` is still open). This is what closes
  the dual-write gap: if the transaction rolls back, the outbox row rolls back
  with it, so "state changed" and "someone was told" can never diverge.
- Every event class implements `App\Support\Events\DomainEvent`:
  `aggregateType()`, `aggregateId()`, `eventType()` (stable dot-namespaced
  string), `payload()` (JSON-serialisable), plus a static
  `fromPayload(array): self` factory so the relay can rebuild the event from a
  stored row.

### Relay

- `App\Jobs\RelayOutboxEventsJob` — queued, scheduled **every 10 seconds**
  via `Schedule::job(new RelayOutboxEventsJob())->everyTenSeconds()->withoutOverlapping()`
  in `routes/console.php`.

  > ⚠️ **This REQUIRES the `* * * * * php artisan schedule:run` cron entry on
  > every environment that should deliver events.** This entry was found
  > **missing on production** and added this session. Without it, `outbox_events`
  > rows accumulate unpublished forever: no internal listeners fire and no
  > webhooks are delivered, silently.

- Per run: reads up to 200 unpublished rows with `attempts < 5`, oldest first.
  For each: looks up the event class in `EVENT_MAP`, rebuilds it via
  `fromPayload()`, `event()`-dispatches it (so queued Listeners run), then calls
  `deliverWebhooksFor($row)`, then stamps `published_at`.
- Failure of one row increments its `attempts` and leaves it for the next run;
  after 5 attempts it is logged and left alone (not deleted). An unknown
  `event_type` is marked published immediately (nothing can ever dispatch it).

### Fan-out

1. **Internal** — queued Laravel Listeners on the typed event (e.g.
   `OpenComplianceCaseOnOrderAwarded`, `RecordLotEventOnShipmentCheckpoint`).
   These are the old Observer side effects, moved to run async and typed.
   Observers migrate one at a time as their context is migrated; an unmigrated
   Observer still does its work inline.
2. **Webhooks** — `RelayOutboxEventsJob::deliverWebhooksFor()` resolves the
   company (or companies) that "own" the event, then dispatches a
   `DeliverWebhookJob` for each active `WebhookSubscription` of that company
   whose `event_types` includes this row's `event_type`. Owner resolution is
   per-event-type (payloads do not carry a uniform `company_id`) — see the
   method's doc block and [`../api/WEBHOOKS.md`](../api/WEBHOOKS.md).

---

## 4. How to add a new domain event

1. **Create the event class** in `app/Domain/{Context}/Events/`, implementing
   `App\Support\Events\DomainEvent`: constructor with typed readonly props,
   `fromPayload()`, `eventType()` (new dot-namespaced string), `payload()`,
   `aggregateType()`, `aggregateId()`. Keep the payload flat and minimal —
   IDs plus a few scalars; consumers re-fetch detail over the API.
2. **Register it in `App\Jobs\RelayOutboxEventsJob::EVENT_MAP`**
   (`'context.thing' => YourEvent::class`).
3. **Add owning-company resolution** in
   `RelayOutboxEventsJob::deliverWebhooksFor()` — either read a `company_id`
   the handler put on the payload directly (preferred — mirrors
   `quote.declined` / `company.verified`), or add a `resolve…CompanyId()`
   lookup (mirrors `compliance_case.opened`). Events with two owners
   (cf. `dispute.opened`) resolve to a list.
4. **Record it via the trait inside a transaction** — from the Command handler
   (`use RecordsOutboxEvents; … $this->recordOutboxEvent(new YourEvent(...))`),
   which the `CommandBus` already wraps in `DB::transaction()`. Never record
   after the state change has committed.
5. **Add it to the webhook catalog** — a row in
   [`../api/WEBHOOKS.md`](../api/WEBHOOKS.md) (event type, when it fires,
   payload fields, recipient) **and** to
   `App\Filament\Exporter\Resources\WebhookSubscriptions\Schemas\WebhookSubscriptionForm::EVENT_TYPES`
   so companies can actually subscribe (that list is currently stale — see
   [`GAPS.md`](GAPS.md)).
6. **Write the tests**: (a) the outbox row is created in the same transaction
   as the state change and rolls back with it on a forced failure; (b) the
   relay dispatches the matching listener; (c) an active subscription receives
   a signed `DeliverWebhookJob`; (d) the internal listener's side effects match
   what the old Observer did.
