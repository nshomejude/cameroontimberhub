# API-First / API-as-a-Product / DDD / CQRS / Event-Driven Architecture Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan phase-by-phase. This is a multi-month program executed incrementally via strangler-fig migration, NOT a rewrite. Steps use checkbox (`- [ ]`) syntax for tracking across many future sessions.

**Goal:** Evolve Cameroon Timber Hub from a Blade/Filament monolith with one narrow mobile API into an API-first platform where the API is a first-class product (versioned, documented, monetizable, webhook-driven), internally organized around Domain-Driven Design bounded contexts with CQRS command/query separation and an event-driven backbone — without a rewrite and without breaking the 1,326 passing tests or the live production site at any point.

**Architecture:** Strangler-fig migration. Every existing Filament/Blade/Livewire screen keeps working throughout; each bounded context is refactored in place to route its writes through Commands and its reads through Queries, emit real domain events via a transactional Outbox, and expose 100% of its capability through `/api/v1`+. The web UI becomes a first-party API consumer, not a parallel code path.

**Tech Stack:** Laravel 13 (existing), no new framework. Thin in-house CommandBus/QueryBus (no external CQRS package — the codebase's own container/pipeline is sufficient and keeps this debuggable). Outbox pattern via a new `outbox_events` table + queued relay (existing Redis/queue infra, no message broker yet). OpenAPI 3.1 via a Laravel-native generator. Sanctum (already installed) for API-key/token auth, extended with scoped abilities and per-key rate limits.

---

## Current State (as of 2026-09-09)

- **UI:** Blade + Livewire (public site), Filament 5 (`/admin` staff panel, `/dashboard` exporter panel). Controllers call Services directly and return Views.
- **API:** `routes/api.php`, versioned at `/v1`, Sanctum auth, scoped explicitly as *"the transport layer for the React Native buyer app"* — browse + RFQ + quotes only. Not documented, not a product, no external API keys, no webhooks.
- **Domain logic:** ~50+ `App\Services\*` classes plus a growing set of `App\Actions\*` (two-person-control flows) and Eloquent Observers (`ProductObserver`, `OrderObserver`, `ShipmentObserver`, `CompanyObserver`, `CompanyDocumentObserver`, `OrderDocumentObserver`) doing cross-cutting side effects synchronously, in-request. This is *informal* event-driven behavior — Observers ARE domain-event reactions, they're just synchronous, untyped, and invisible to anything outside the PHP process.
- **No CQRS:** reads and writes both go through Eloquent models directly from controllers/services; no dedicated read models.
- **No message bus / outbox:** every side effect (fraud detection, lot-event recording, compliance-case creation) happens inline in the same request, wrapped only in try/catch — safe, but not replayable, not visible to an external subscriber, and couples the write path to every side effect's latency.

## Target Architecture

### 1. Domain-Driven Design — Bounded Contexts

Reorganize (incrementally, context by context — not a big-bang `app/` restructure) around these contexts, each owning its own models/events/commands/queries and talking to other contexts ONLY via domain events or an explicit application-service call, never by reaching into another context's Eloquent models directly:

| Context | Owns (existing models) |
|---|---|
| **Identity & Access** | User, Company, roles/permissions, Verification, VerificationBadge |
| **Catalog** | Product, Species, Capacity, CarbonProject |
| **Trade** | Rfq, Quote, Order, TradeAssuranceAgreement/Milestone |
| **Logistics & Traceability** | Shipment, CheckpointUpdate, TimberLot, LotEvent, LotTransformation |
| **Compliance & Trust** | ComplianceRule, ComplianceCase, RegulatorySource, Inspection, Inspector, Dispute, RiskAssessment, FraudSignal |
| **Commerce & Billing** | Plan, Subscription, Payment, AiSetting (billing-adjacent — API keys/usage metering will live here too) |
| **Intelligence** | MarketIntelligenceSnapshot, PlatformKpiSnapshot, ComplianceAssistantQuery, DocumentExtraction |
| **Platform** | Audit log, notifications, feature flags, webhooks, API keys |

This table is a target, not a day-one refactor. Bounded-context boundaries get *enforced* only as each context is touched during its migration phase (see Phasing below) — e.g. a static-analysis rule (Deptrac or a simple custom Composer script) added per-context once it's "done", not upfront for all eight at once.

### 2. CQRS (pragmatic, not dogmatic)

- **Commands** — intent-revealing, one class per write use case (`AwardQuoteCommand`, `RecordShipmentCheckpointCommand`, `FinaliseInspectionCommand`, `OpenDisputeCommand`). Each has exactly one handler. A thin `CommandBus` (container-resolved, no package) wraps every dispatch in: a DB transaction → authorization check → validation → the handler → outbox event(s) flushed on commit.
- **Queries** — dedicated read-model classes (`ListOpenRfqsForCompanyQuery`, `SupplierPerformanceIndexQuery`) resolved through an equally thin `QueryBus`. Most queries stay backed by the existing Eloquent models (no premature read/write DB split); only genuinely expensive aggregate views (Market Intelligence, Platform Operations — already shaped this way) get a real denormalized projection table, populated by outbox-event listeners.
- **No separate database, no event sourcing.** This is CQRS as *code organization and a stable seam for the API*, not infrastructure duplication. Revisit only if a specific read path proves it needs it.

### 3. Event-Driven Backbone

- **Outbox pattern:** a new `outbox_events` table (`id`, `aggregate_type`, `aggregate_id`, `event_type`, `payload` jsonb, `occurred_at`, `published_at` nullable, `attempts`). Every Command handler that changes state writes its domain event(s) into this table **inside the same DB transaction** as the state change — this is what avoids the dual-write problem (state changes but the event never fires, or vice versa).
- **Relay worker:** a queued job (`RelayOutboxEventsJob`, scheduled every few seconds via the existing queue) reads unpublished outbox rows and: (a) dispatches typed internal Laravel events (`OrderAwarded`, `ShipmentCheckpointRecorded`, `InspectionFinalised`, `DisputeOpened`, `ComplianceCaseOpened`, …) for in-process listeners — this is what today's Observers do inline, moved to be async, typed, and replayable; (b) hands matching events to the Webhook delivery service (see §4) for any external subscriber.
- **Existing Observers migrate gradually**: each Observer's logic becomes a queued Listener on the corresponding typed event, one Observer at a time, as its owning context is migrated — never all at once.
- **No external broker yet.** Redis/the existing queue connection is enough at current scale. A move to Redis Streams or SQS is a later, isolated decision if fan-out volume ever demands it — explicitly out of scope for this plan.

### 4. API-First, API-as-a-Product

- **Completeness discipline:** from the pilot context onward, no new capability ships as UI-only. The API is written first (or alongside), Filament/Blade calls the same Command/Query layer the API uses — never a shortcut around it.
- **OpenAPI 3.1**, generated from route + FormRequest annotations (evaluate `dedoc/scramble` — zero-annotation inference from Laravel code, good fit here — vs `l5-swagger`'s explicit annotations; default to Scramble for less maintenance burden). Published at `/docs/api` (public, read-only, no auth needed to browse the spec).
- **API keys as a real product surface**, not a config value: new `api_keys` table (company-owned, scoped abilities — `products:read`, `rfqs:write`, etc. — reusing Sanctum's ability system), per-key rate-limit tier tied to the existing `plans`/`subscriptions` billing model (a company's Plan determines its API rate limit and which scopes it can request). **Key issuance is two-person + step-up-2FA gated exactly like the AI provider key flow already built** (`App\Actions\Ai\{Request,Approve}AiApiKeyChange`) — an API key is just as sensitive as an AI provider key and should get the same rigor, so this reuses that pattern rather than inventing a third variant.
- **Versioning discipline:** `/api/v1` is additive-only from here forward (new optional fields, new endpoints); any breaking change ships as `/api/v2` behind a documented deprecation/sunset schedule for v1.
- **Rate limiting** keyed by API key (not just IP/user) — extends the app's existing `throttle:` middleware usage.
- **Webhooks** — the other half of "event-driven" meeting "API as a product": `webhook_subscriptions` (company_id, url, subscribed event types, HMAC secret) + `webhook_deliveries` (attempt log, response code, retry count) tables. The outbox relay (§3) hands qualifying events to a `DeliverWebhookJob` with signed payloads, exponential backoff, and a dead-letter state visible in the admin panel (mirrors the existing `FraudSignal`/`ComplianceCase` admin-review-queue pattern).
- **SDKs** — generated later from the OpenAPI spec (TypeScript + PHP clients). Explicitly a Phase 3+ nice-to-have, not a blocker for anything above.

### 5. Migration Strategy — Strangler Fig

No rewrite. Order of operations:

- **Phase 0 — Foundations** (this plan's first dispatch): CommandBus/QueryBus scaffolding + one proven example of each; the `outbox_events` table + relay worker + 2-3 real events formalized; OpenAPI tooling installed with a baseline spec for the *existing* `/api/v1`; the `api_keys` table + two-person/2FA-gated issuance flow; the `webhook_subscriptions`/`webhook_deliveries` tables + delivery service skeleton. Nothing in the existing UI changes behavior yet — this phase is pure infrastructure, proven with tests, sitting alongside the current code.
- **Phase 1 — Pilot context: Trade** (Rfq/Quote/Order/Trade Assurance). Chosen because it's the commercial core, already has a partial API surface, and its state machine (award → confirm → ship → deliver) maps cleanly onto Commands and typed events. Migrate its writes to Commands, its heavy reads to Queries, expand `/api/v1` to 100% parity with what the web UI can do here, publish its OpenAPI section, wire real webhook events (`order.awarded`, `order.shipped`, `quote.accepted`).
- **Phase 2 — Logistics & Traceability** (Shipment/CheckpointUpdate/TimberLot/LotEvent) — natural fit, checkpoints are already event-shaped; and **Compliance & Trust** (ComplianceCase/Inspection/Dispute) in parallel, since both were built this session with clean service boundaries already.
- **Phase 3 — Developer-facing product layer**: self-serve API key management UI in the exporter panel, webhook subscription UI, published OpenAPI docs site, rate-limit tiers wired to real Plans.
- **Phase 4 — Remaining contexts**: Catalog, Identity & Access, Commerce & Billing, Intelligence.

Each phase ships independently, deploys independently, and leaves the site fully working at every commit — exactly the deploy discipline already used all session.

---

## Phase 0 — Detailed Tasks (dispatch now)

### Task 0.1 — CommandBus / QueryBus foundation

**Files:**
- Create: `app/Support/Bus/CommandBus.php`, `app/Support/Bus/QueryBus.php`, `app/Support/Bus/Command.php` (marker interface), `app/Support/Bus/Query.php` (marker interface), `app/Support/Bus/HandlesCommand.php` / `HandlesQuery.php` (handler interfaces)
- Create one proof-of-pattern migration: `app/Domain/Trade/Commands/AwardQuoteCommand.php` + `AwardQuoteHandler.php`, wired to replace (or wrap, additively) the existing quote-award code path in `QuoteService`/`OrderService` — **read those services fully first**; do not duplicate their logic, extract it behind the handler.
- Create one proof-of-pattern Query: `app/Domain/Trade/Queries/ListBuyerOrdersQuery.php` + handler, backing the existing `AccountController::orders()` read.
- Test: full Pest coverage for both, plus a regression test proving the existing `/account/orders` page and the existing quote-accept flow behave identically before/after.

### Task 0.2 — Outbox pattern + first 3 domain events

**Files:**
- Migration + model: `outbox_events` table, `App\Models\OutboxEvent`.
- `app/Support/Events/DomainEvent.php` (base interface: `aggregateType()`, `aggregateId()`, `eventType()`, `payload()`).
- Three real events, replacing the equivalent Observer logic (read `OrderObserver`, `ShipmentObserver` first): `App\Domain\Trade\Events\OrderAwarded`, `App\Domain\Logistics\Events\ShipmentCheckpointRecorded`, `App\Domain\Compliance\Events\ComplianceCaseOpened`.
- `app/Jobs/RelayOutboxEventsJob.php` (queued, scheduled every 10s in `routes/console.php`'s scheduler) — reads unpublished rows, dispatches the matching Laravel event, marks `published_at`.
- The three events' actual Laravel Listeners take over exactly what the corresponding Observer currently does inline — the Observer itself becomes a thin "write to outbox" call, nothing more.
- Test: outbox row created transactionally with the state change (assert both roll back together on a forced failure), relay job dispatches the correct listener, listener side effects match what the Observer used to do synchronously.

### Task 0.3 — OpenAPI baseline for existing `/api/v1`

**Files:**
- Install `dedoc/scramble` (evaluate against `l5-swagger` first — document the choice in a code comment).
- Configure it against the existing `routes/api.php` v1 routes (no route changes needed — Scramble infers from FormRequests/return types).
- Publish route: `GET /docs/api` (public, read-only spec viewer).
- Test: the generated spec is valid OpenAPI 3.1 (schema-validate it in a Pest test), and covers every existing `/api/v1` route (assert route count vs spec path count match).

### Task 0.4 — API key management (two-person + 2FA gated, mirrors AI keys)

**Files:**
- Migration + model: `api_keys` (company_id, name, key_hash, abilities jsonb, rate_limit_tier, last_used_at, revoked_at) — mirror `AiSetting`'s encrypted-at-rest discipline for the key itself (store only a hash, like Sanctum does natively — consider using Sanctum's own `PersonalAccessToken` with custom abilities instead of a bespoke table; **read `laravel/sanctum`'s existing usage in this codebase first** and prefer extending it over inventing a parallel token system).
- `app/Actions/ApiKeys/{Request,Approve}ApiKeyIssuance.php` — same two-person + `TwoFactorStepUp` gate as `App\Actions\Ai\*`, reused directly (don't reimplement the gate logic, extract it to a shared trait/service if duplicated a third time).
- Admin Filament resource for issuance/revocation, gated on a new `api-keys.manage` permission (added additively to `RolesAndPermissionsSeeder`).
- Per-key rate limiting: a new rate limiter definition keyed by `$request->user()->currentAccessToken()?->id`.
- Test: issuance requires a different approver + recent 2FA (mirror `AiApiKeyChangeControlTest.php` exactly), a request against `/api/v1` with a scoped key succeeds only for abilities it holds, rate limit enforced per key not per IP.

### Task 0.5 — Webhook subscriptions + delivery skeleton

**Files:**
- Migrations + models: `webhook_subscriptions` (company_id, url, event_types jsonb, secret_hash, is_active), `webhook_deliveries` (subscription_id, event_type, payload jsonb, response_code, attempt, delivered_at).
- `app/Services/Webhooks/WebhookDeliveryService.php` — HMAC-SHA256 signs the payload (header `X-CTH-Signature`), delivers via HTTP with a short timeout, exponential backoff (3 attempts: immediate, +1min, +10min), marks dead after final failure.
- `app/Jobs/DeliverWebhookJob.php` (queued).
- Wire it as one more consumer inside `RelayOutboxEventsJob` (Task 0.2) — when an outbox event's type matches an active subscription, dispatch a delivery job. **Coordinate file ownership with Task 0.2's agent** — this task should only ADD a call in `RelayOutboxEventsJob`, not restructure it; if Task 0.2 hasn't landed yet, stub against the interface and note the integration point clearly for a fast follow-up wire-up.
- Admin Filament resource: read-only delivery log + a "retry" action, gated on `api-keys.manage` (same permission, since webhook/API management is one product surface).
- Test: signed payload verifies correctly, retry/backoff schedule is correct, dead-letter state is reachable and visible.

---

## Explicitly Out of Scope for Phase 0

- No message broker (Kafka/RabbitMQ/SQS) — the DB outbox + existing queue is enough at current scale.
- No event sourcing / no rebuilding aggregate state from event history — outbox events are for *notification and replay of side effects*, not the system of record (Eloquent + Postgres stays the source of truth).
- No `app/` directory restructure into `app/Domain/*` for all eight contexts at once — only `Trade` (Task 0.1) gets a `Domain/` namespace in Phase 0, as the pilot; the rest follow their own phase.
- No SDK generation, no public developer signup flow, no monetized API pricing tier — these are Phase 3.
- No breaking change to `/api/v1`, no change to any existing Blade/Filament screen's behavior — Phase 0 is additive infrastructure only, verified by full-suite regression at every step.

## Self-Review

**Spec coverage:** every element the user asked for — API-first, API-as-a-product, event-driven, domain-driven, CQRS — has a concrete section and Phase 0 task above. No placeholders: every task names exact files, exact classes, and what to read before writing.

**Risk called out honestly:** this is a multi-month program even with agent throughput; Phase 0 alone is 5 substantial, parallelizable-with-care tasks. Two tasks (0.2 outbox, 0.5 webhooks) share one file (`RelayOutboxEventsJob`) — sequenced with an explicit coordination note rather than pretending they're fully independent.
