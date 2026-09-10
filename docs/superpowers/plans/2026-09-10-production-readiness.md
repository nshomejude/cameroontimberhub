# Production Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan batch-by-batch. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the already-live Cameroon Timber Hub platform from "works and is deployed" to "hardened, observable, trust-layer-complete, and bilingual" — closing every remaining item that can be built without an external business or legal decision.

**Architecture:** Modular monolith (Laravel 13 / Filament 5 / PostgreSQL 16 / Redis), API-first with a transactional outbox and CQRS bus. All 8 tracked architecture gaps are closed (`docs/architecture/GAPS.md`). This plan does not restructure anything — it fills gaps and hardens.

**Tech Stack:** PHP 8.3, Pest 4 (`php artisan test --parallel --processes=4`), `endroid/qr-code` v6 (already used for certificates/waybills), `spatie/laravel-activitylog` (hash-chained via `ChainedActivity`), `lang/{en,fr}/messages.php`, `SetLocale` middleware.

---

## Scope

### In scope — buildable now, no external dependency

| Batch | What | Why it matters for "production ready" |
|---|---|---|
| **A. Observability & ops hardening** | error tracking, health check, log/queue reliability, security-header + rate-limit audit, DB index review, backup verification runbook | The platform is live; today a 500 is only visible if someone reads `storage/logs`. Non-negotiable for production. |
| **B. Trust-layer finishers (§1.1–1.3)** | product `CTH-CMR-…` id + QR + public `/verify/product/{id}`; receipt integrity hash-chain; per-product documents with verified/expired status | The platform's entire value proposition is "verified & traceable". These are the visible holes in that story. |
| **C. Reputation & price signal (§1.10, §1.13)** | recompute job for `response_rate_percent` / `on_time_delivery_percent` / `orders_completed` / dispute history; `PriceObservation` schema + first two emitters (order award, quote submit) | 1.10 columns render today but are static/seeded. 1.13 unblocks all of §8.1 and needs its schema in before more trade data accrues. |
| **D. i18n string coverage (§1.9)** | extract hard-coded English across public views + Filament panels into `lang/*/messages.php`; French pass; `<html lang>` + `hreflang`; locale switcher in the header | It is a Cameroon platform. French is not optional. Infra (middleware + route + `fr` file) is done; coverage is ~7 views. |
| **E. Cleanup** | 1.5.2b category-tree wiring for `/buy-cameroon-wood`; 0.7b feature-gate the dormant account roles that now have features; carried-over SEO defects (species meta truncation, placeholder JSON-LD contact data, `og:type`, duplicate `<h1>`); 0.1b document-consumer migration **or** a documented decision to keep the split | Loose ends that make the codebase inconsistent. |
| **F. Carbon registry core (§2.6, partial)** | `CarbonProject` GeoJSON boundary (reuse `GeoJsonPolygon`), status machine, `CTH-CARB-…` id + QR + public verification page — **registry only, no credit trading** | The registry half of §2.6 has the same shape as the timber-lot passport already built. Credit lifecycle (§2.7) stays out — see below. |

### Out of scope — blocked on a decision, not on engineering

Building these now would mean fabricating integrations, legal frameworks, or commercial terms that do not exist. That is explicitly against this project's discipline (see `docs/GAP_PLAN.md` passim).

| Item | Blocked on |
|---|---|
| §0.6 / 0.6b `Organisation.type` migration | product decision on how `exporter`/`trader` map to a role/capability model |
| §0.8b Certificate physical-production layer | which security tier + print/label supplier TimberHub engages |
| §0.9b commercial/billing engine (non-supplier segments) | payment-provider decision (owner has confirmed: manual admin plan assignment for now) |
| §2.1 live GPS tracking (Traccar/OwnTracks, geofences) | a Traccar deployment + a broadcasting driver (`BROADCAST_CONNECTION=log` today) + device procurement |
| §2.2–2.5, 2.8 Forest Sponsorship | COSUMAF/CEMAC counsel sign-off on whether sponsorship is offered at all |
| §2.7 carbon **credit lifecycle** & trading | carbon-registry accreditation + a registry integration (Verra/Gold Standard) |
| §2.9–2.11 residue/equipment/finance directories, escrow, price intelligence data product | each depends on a partner or a legal/competition review (CEMAC) |
| §2.11 / Phase 3 price indices & data product | published methodology + legal review against CEMAC competition rules before launch |
| PEFC P.1 / P.3 / P.4 | someone must apply to PEFC for API access and be approved |
| §3 Phase 3 (route risk maps, insurance directory, Academy, warehousing, public-chain anchoring) | each needs a partner or a business model decision |

These stay tracked in `docs/GAP_PLAN.md`. Each becomes a one-batch plan the day its blocker clears.

---

## Execution model

Batches A–F are independent and can run in parallel via `superpowers:subagent-driven-development` — one fresh subagent per task, spec-review then code-review after each, commit-only (the orchestrator deploys). Every task:

- runs `php artisan test --parallel --processes=4` before committing (shared-DB contention noise is expected and not a failure)
- `git add <explicit paths>` — never `-A`
- follows the established conventions (CommandBus resolution, `DomainEvent` shape + `RelayOutboxEventsJob::EVENT_MAP` for any new event, `{data}` / `{error:{code,message,request_id}}` API shapes, exporter-resource company-scoping pattern from `CapacityResource`, shared test helpers in `tests/Support/`)
- does **not** spawn its own sub-agents

After each batch lands: merge → full sequential suite → deploy to production (`git pull` + `composer dump-autoload -o --no-dev` + `config:cache route:cache view:cache` + `filament:cache-components` + `queue:restart` + FPM reload) + push to GitHub + browser smoke-test the new surface.

Recommended order: **A first** (you cannot safely iterate on a live platform you cannot observe), then B and D in parallel (highest user-visible value), then C, E, F.

---

## Batch A — Observability & ops hardening

**Files:**
- Create: `app/Http/Controllers/HealthController.php`, `routes/web.php` (health route), `config/logging.php` (channel), `.env.example` (documented keys), `docs/ops/RUNBOOK.md`
- Modify: `bootstrap/app.php` (exception reporting), `app/Providers/AppServiceProvider.php` (rate-limiter audit)
- Test: `tests/Feature/HealthCheckTest.php`, `tests/Feature/SecurityHeadersTest.php`

### Task A1: Health-check endpoint

- [ ] **Step 1: Write the failing test**

`tests/Feature/HealthCheckTest.php`:

```php
<?php

it('reports healthy when the database and cache are reachable', function () {
    $this->getJson('/up/health')
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'queue'], 'time']);
});

it('does not require authentication', function () {
    $this->getJson('/up/health')->assertOk();
});

it('returns 503 when a check fails', function () {
    // force a bad cache store
    config()->set('cache.default', 'nonexistent-store');
    $this->getJson('/up/health')->assertStatus(503)->assertJson(['status' => 'degraded']);
});
```

- [ ] **Step 2: Run — expect FAIL** (`Route [/up/health] not defined`)

Run: `php artisan test tests/Feature/HealthCheckTest.php`

- [ ] **Step 3: Implement**

`app/Http/Controllers/HealthController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Liveness + readiness probe. Public and unauthenticated by design — it is a
 * load-balancer / uptime-monitor target, exposes no data, and must answer
 * even when the app is otherwise refusing traffic.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->safe(fn () => DB::connection()->getPdo() !== null),
            'cache' => $this->safe(function (): bool {
                Cache::put('health:ping', 1, 5);

                return Cache::get('health:ping') === 1;
            }),
            'queue' => $this->safe(fn () => DB::table('jobs')->count() < 10_000), // backlog guard
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    private function safe(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (\Throwable) {
            return false;
        }
    }
}
```

Add to `routes/web.php` (near the top, outside any group):

```php
Route::get('/up/health', \App\Http\Controllers\HealthController::class)->name('health');
```

- [ ] **Step 4: Run — expect PASS**
- [ ] **Step 5: Commit** (`git add app/Http/Controllers/HealthController.php routes/web.php tests/Feature/HealthCheckTest.php && git commit -m "Add /up/health readiness probe"`)

### Task A2: Error tracking

- [ ] **Step 1:** Decide the sink. Sentry (`sentry/sentry-laravel`) is the default; if the owner prefers self-hosted, use the `LOG_STACK` with a dedicated `errors` channel + a daily digest command. **This task needs a one-line owner confirmation of the sink before writing the integration** — until then, implement the fallback: a `logging.php` `errors` channel (daily, 14-day retention) that `bootstrap/app.php`'s `->withExceptions()` reports every unhandled exception to, plus `app/Console/Commands/ErrorDigestCommand.php` (scheduled daily) that emails a count + top-5 by frequency to `config('mail.ops_address')`.
- [ ] **Step 2–5:** TDD the digest command against seeded log lines; wire the schedule in `routes/console.php`; commit.

### Task A3: Security-header & rate-limit audit

- [ ] **Step 1: Write `tests/Feature/SecurityHeadersTest.php`** asserting on `GET /`: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` (both already present — lock them in), `Strict-Transport-Security` with `max-age >= 31536000`, `Referrer-Policy`, and a `Content-Security-Policy` (report-only to start).
- [ ] **Step 2:** Run — the CSP + HSTS assertions fail.
- [ ] **Step 3:** Add a `SecurityHeaders` middleware on the `web` group. HSTS only when `request()->secure()`. CSP `Content-Security-Policy-Report-Only` first — allow `'self'`, the CDN hosts already used, `data:` images, inline styles (Filament/Tailwind need it initially); add a `/csp-report` collector route that logs violations to the `errors` channel. Do **not** ship enforcing CSP in this task — ship report-only, collect a week of reports, tighten in a follow-up.
- [ ] **Step 4:** Review every `RateLimiter::for()` in `AppServiceProvider` — confirm each public write path (contact, inquiry, RFQ, quote decision, receipt/certificate verify, checkpoint track, demo login, app-notify) has a limiter and the limits are sane for production traffic. Document the table in `docs/ops/RUNBOOK.md`.
- [ ] **Step 5:** Commit.

### Task A4: Queue & schedule reliability

- [ ] **Step 1:** Add `tests/Feature/QueueReliabilityTest.php` — assert `RelayOutboxEventsJob`, `DeliverWebhookJob`, `SendDocumentExpiryReminderJob` all declare `$tries` / `$backoff` and a `failed()` handler that logs to the `errors` channel.
- [ ] **Step 2–3:** Add the missing `failed()` handlers / retry config. Add `app/Console/Commands/QueueHealthCommand.php` — scheduled every 15 min, alerts if `failed_jobs` count grew or the oldest pending job in `jobs` is older than 5 min (outbox relay starvation).
- [ ] **Step 4:** Confirm on production: `crontab -l -u timberhub` shows `schedule:run`; `systemctl status timberhub-queue` is active; `php artisan schedule:list` shows the outbox relay + expiry commands.
- [ ] **Step 5:** Commit + document restart procedure in `RUNBOOK.md`.

### Task A5: DB index & N+1 audit

- [ ] **Step 1:** `php artisan db:show --counts`; for the 10 largest tables, `EXPLAIN ANALYZE` the query behind each Filament list page's default sort + the public directory/marketplace queries.
- [ ] **Step 2:** Add any missing composite indexes as one additive migration (`2026_09_1x_add_production_indexes.php`). Likely candidates: `orders(company_id, status)`, `quotes(company_id, status)`, `rfq_company(company_id, status)`, `outbox_events(published_at) WHERE published_at IS NULL` (partial), `activity_log(subject_type, subject_id)`, `documents(owner_type, owner_id, expires_at)`.
- [ ] **Step 3:** Enable `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider::boot()` so N+1s fail loudly in dev/CI and are logged (not thrown) in prod. Fix whatever the test suite then surfaces.
- [ ] **Step 4–5:** Full suite green; commit.

### Task A6: Backup & restore runbook

- [ ] **Step 1:** Verify what backup exists on the host (Hostinger panel / `pg_dump` cron / snapshot). If none: add a `pg_dump` cron (daily, gzip, 7-day local + offsite copy) — **needs owner confirmation of an offsite target** (S3 bucket / another host).
- [ ] **Step 2:** Write `docs/ops/RUNBOOK.md` covering: deploy procedure, rollback (`git reset --hard <sha>` + cache rebuild + FPM reload), DB restore drill, queue restart, cache flush, how to read `/up/health`, where errors land, the rate-limit table, and the "who to call" for each out-of-scope blocker.
- [ ] **Step 3:** Do one restore drill against a scratch database; record the result in the runbook.
- [ ] **Step 4:** Commit.

---

## Batch B — Trust-layer finishers

**Files:**
- Create: `app/Support/ProductIdentifier.php`, `app/Services/ProductQrCodeService.php`, `app/Http/Controllers/Public/ProductVerificationController.php`, `database/migrations/2026_09_1x_add_public_id_to_products.php`, `database/migrations/2026_09_1x_add_integrity_chain_to_receipts.php`, `app/Models/Concerns/ChainsIntegrity.php` (or extend the existing chain helper), `resources/views/public/products/verify.blade.php`
- Modify: `app/Models/Product.php` (public id + `HasDocuments`), `app/Models/Receipt.php`, `app/Services/ReceiptIssuer.php` (chain on issue), `resources/views/public/products/show.blade.php` (documents block), `routes/web.php`
- Test: `tests/Feature/ProductVerificationTest.php`, `tests/Feature/ReceiptIntegrityTest.php`, `tests/Feature/ProductDocumentsTest.php`

### Task B1: Product public identifier + QR + verification page (§1.1)

- [ ] **Step 1: Write the failing test** — `ProductVerificationTest.php`:

```php
<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Species;

it('assigns a CTH-CMR public id on create', function () {
    $product = Product::factory()->for(Company::factory()->publiclyVisible(), 'company')
        ->for(Species::factory(), 'species')->create();

    expect($product->public_id)->toMatch('/^CTH-CMR-[A-Z]+-\d{5}$/');
});

it('serves a public verification page keyed by public id', function () {
    $product = Product::factory()->active()->for(Company::factory()->publiclyVisible(), 'company')->create();

    $this->get('/verify/product/'.$product->public_id)
        ->assertOk()
        ->assertSee($product->name)
        ->assertSee($product->company->legal_name)
        ->assertDontSee($product->id, false); // never leak the numeric id
});

it('404s an unknown or unpublished product without leaking which', function () {
    $draft = Product::factory()->draft()->create();

    $this->get('/verify/product/'.$draft->public_id)->assertNotFound();
    $this->get('/verify/product/CTH-CMR-XXX-99999')->assertNotFound();
});

it('renders a QR SVG that encodes the verification URL', function () {
    $product = Product::factory()->active()->for(Company::factory()->publiclyVisible(), 'company')->create();

    $svg = app(\App\Services\ProductQrCodeService::class)->svg($product);

    expect($svg)->toContain('<svg')->toContain('</svg>');
});
```

- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement**
  - Migration: additive nullable `products.public_id` (string 40, unique), backfilled by an idempotent `products:backfill-public-ids` command (mirror `products:backfill-categories`), then a second migration setting NOT NULL once backfill is verified.
  - `App\Support\ProductIdentifier::forProduct(Product): string` — `CTH-CMR-{first 3 letters of species common_name, uppercase}-{zero-padded per-species sequence}`. Deterministic, collision-safe via a `DB::transaction` + `lockForUpdate` on a small `product_id_sequences` helper table (mirror `RfqReferenceGenerator`).
  - `Product::booted()` → `creating` hook assigns `public_id` when null.
  - `Product::getRouteKeyName()` stays `slug` for existing routes; the verification route binds explicitly by `public_id`.
  - `ProductQrCodeService` — copy `ShipmentWaybillQrCodeService` verbatim, encode `route('products.verify', $product->public_id)`.
  - `ProductVerificationController@show` — `Product::where('public_id', $id)->where('status', ProductStatus::Active)->firstOrFail()`, eager-load `company`, `species`, `verification`, `documents`, `certificates`. View shows: name, species, verified supplier, verification stage, document list with status, certificate list with check-date, the QR, and a "report a concern" link. Throttle with a new `product-verify` limiter.
  - Route: `Route::get('/verify/product/{publicId}', [ProductVerificationController::class, 'show'])->name('products.verify')`.
- [ ] **Step 4: Run — expect PASS**
- [ ] **Step 5: Commit**

### Task B2: Per-product documents (§1.3)

- [ ] **Step 1: Write `ProductDocumentsTest.php`** — a product exposes `documents()`; the exporter panel has a Documents relation manager scoped to the product's company; the product `show` page's "Certifications" block renders **product documents + certificates**, and when the product has none it says so rather than borrowing the supplier's badges.
- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement**
  - `use HasDocuments;` on `App\Models\Product`.
  - `app/Filament/Exporter/Resources/Products/RelationManagers/DocumentsRelationManager.php` — type (datasheet / legal_origin / fsc_pefc / phytosanitary / other), file upload to the `documents` disk, `issued_at` / `expires_at`, read-only `verification_status`. Company-scoped: a supplier can only attach to their own product (already enforced by the resource's `getEloquentQuery`).
  - `resources/views/public/products/show.blade.php` — replace the certifications block's data source: iterate `$product->documents` (label + status badge: verified / pending / **expired** in red) and `$product->certificates` (with check-date). Remove the fallback to `$product->company->activeBadges()`. Keep the free-text `$product->certification` string as a separate "supplier-stated" line, clearly labelled as unverified.
  - Extend `SendDocumentExpiryReminderJob` coverage note — `Product`-owned documents already flow through it via the polymorphic owner (item 0.1b prerequisites); add a test proving a product document 30 days from expiry produces a reminder.
- [ ] **Step 4: Run — expect PASS**
- [ ] **Step 5: Commit**

### Task B3: Receipt integrity hash-chain (§1.2)

- [ ] **Step 1: Write `ReceiptIntegrityTest.php`**:

```php
<?php

use App\Models\Order;
use App\Models\Receipt;

it('chains each receipt to the previous one', function () {
    $a = Receipt::factory()->create();
    $b = Receipt::factory()->create();

    expect($a->prev_hash)->toBeNull()
        ->and($a->hash)->not->toBeNull()
        ->and($b->prev_hash)->toBe($a->hash)
        ->and($b->hash)->not->toBe($a->hash);
});

it('detects tampering via the verify command', function () {
    Receipt::factory()->count(3)->create();
    $this->artisan('receipts:verify-chain')->assertExitCode(0);

    \DB::table('receipts')->where('id', 2)->update(['amount' => 999999]);

    $this->artisan('receipts:verify-chain')->assertExitCode(1);
});

it('surfaces the integrity state on the public verification page', function () {
    $receipt = Receipt::factory()->create();

    $this->get('/verify/'.$receipt->verification_token)
        ->assertOk()
        ->assertSee('Integrity verified');
});
```

- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement**
  - Migration: additive nullable `receipts.hash` (char 64) + `receipts.prev_hash` (char 64), backfilled in issue-order by an idempotent `receipts:backfill-integrity-chain` command.
  - Reuse the hashing approach from `ChainedActivity` — a `ChainsIntegrity` trait: on `creating`, `prev_hash` = the last receipt's `hash` (global order, `lockForUpdate`), `hash` = `hash('sha256', prev_hash . canonical_json(receipt payload columns))`. Payload = `receipt_number | order_id | issued_at | amount | currency | voided_at`. Immutable after creation (guard `updating` — only `verified_at` / `verification_count` / `voided_at` / `void_reason` may change, and those are excluded from the payload).
  - `receipts:verify-chain` command — walk in issue order, recompute, exit 1 on first mismatch (mirror `activitylog:verify-chain`). Schedule it daily.
  - Receipt verification view (`/verify/{token}`) — add an "Integrity verified" / "Integrity check failed" line.
- [ ] **Step 4: Run — expect PASS**
- [ ] **Step 5: Commit**

---

## Batch C — Reputation & price signal

### Task C1: Reputation recompute job (§1.10)

- [ ] **Step 1: Write `tests/Feature/ReputationRecomputeTest.php`** — a company with 4 completed orders, 3 delivered on/before `expected_delivery_at`, 1 late, 1 open dispute → `on_time_delivery_percent` becomes 75, `orders_completed` becomes 4, `disputes_count` reflects 1; a company with no order history keeps null/0, not a fabricated number.
- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement** `app/Services/ReputationService::recompute(Company): void` + `app/Console/Commands/RecomputeReputationCommand.php` (nightly, all companies with ≥1 order). Metrics computed strictly from real rows:
  - `orders_completed` = orders in `completed` status
  - `on_time_delivery_percent` = delivered-on-time / delivered, null if 0 delivered
  - `response_rate_percent` = quotes submitted / RFQs routed to the company in the last 90 days, null if 0 routed
  - `disputes_count` = disputes where the company is respondent
  A company below the sample threshold (e.g. < 3 delivered orders) shows "Not enough history yet", never a percentage — mirror the existing `eudr_risk_note` "not yet assessed" discipline.
- [ ] **Step 4:** Surface the recompute timestamp on the supplier profile ("reputation as of <date>"). Full suite green.
- [ ] **Step 5: Commit**

### Task C2: `PriceObservation` foundation (§1.13, schema + first two emitters)

- [ ] **Step 1: Write `tests/Feature/PriceObservationTest.php`** — awarding an order emits one `PriceObservation` per line item with `source = transacted`, real species/quantity/unit/currency/unit_price/region from the order; submitting a quote emits `source = quoted`; an RFQ with no species link emits nothing (no fabricated species).
- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement** — follow `docs/PRICE_DATA_STANDARD.md`:
  - New enums `App\Enums\PriceBasis`, `App\Enums\PriceVolumeBand`; add `Other` to `RfqIncoterm` to match its own CHECK constraint.
  - `price_observations` table + `PriceObservation` model: `species_id`, `product_type`, `source` (transacted / quoted / listed / …), `unit_price`, `currency`, `unit`, `basis`, `region`, `quantity`, `volume_band`, `observed_at`, plus a nullable polymorphic `origin` (`origin_type` / `origin_id`) back to the order/quote it came from.
  - Additive columns the standard needs and that are buildable today: `products.basis` / `products.region`, `quote_items.moisture_content`, `order_items.moisture_content`.
  - Emit **through the outbox / a listener on existing domain events** — `OrderAwarded` → `RecordTransactedPriceObservations`, quote submission → `RecordQuotedPriceObservations`. Register any new event in `RelayOutboxEventsJob::EVENT_MAP` if one is needed; prefer listening to `OrderAwarded` which already exists.
  - **No** `PriceBand` computation, no product-page surfacing, no landed-cost estimator in this task — those are §2.11, gated on a CEMAC competition-law review. This task only lands the schema and starts collecting real observations so the data exists when that review clears.
- [ ] **Step 4:** Full suite green.
- [ ] **Step 5: Commit**

### Task C3: Build the missing `CompanySpecies` model (§1.13 prerequisite)

- [ ] `company_species` is schema-only today. Add the `CompanySpecies` model + a company-scoped Filament resource in the exporter panel (species handled, with moisture_content / dimensions / unit / basis / region per the standard). Emit a `listed` `PriceObservation` on save. TDD, commit.

---

## Batch D — i18n string coverage (§1.9)

**This is a large, mechanical batch — treat it as its own sub-plan.** Split by surface so subagents don't collide:

### Task D1: Public marketing + directory views
- [ ] Extract every hard-coded user-facing English string in `resources/views/{home,public/*,components/layouts/*}` into `lang/en/messages.php` with dotted keys (`nav.*`, `home.*`, `directory.*`, `marketplace.*`, `product.*`, `rfq.*`). Replace with `__('...')`.
- [ ] Mirror every new key into `lang/fr/messages.php` with natural Cameroon-trade French (keep the existing file's disclaimer about professional legal review).
- [ ] `<html lang="{{ app()->getLocale() }}">` in the layout; add `hreflang` alternates for `en`/`fr` on canonical public pages.
- [ ] Header locale switcher (EN / FR) posting to `route('locale.set', ...)`.
- [ ] Test: `AuthPagesTest`-style — each public page renders in `fr` locale without an untranslated `messages.*` key leaking (assert no literal `messages.` substring in the HTML).

### Task D2: Buyer account panel (`resources/views/public/account/*`, `layouts/account.blade.php`)
- [ ] Same treatment, `account.*` keys.

### Task D3: Filament panels (admin + exporter)
- [ ] Filament resource labels, nav labels, section headings, action labels, empty-state text, and the custom workspace pages. Filament reads `__()` in most label positions; where it doesn't, set explicit translated `$navigationLabel` / `$modelLabel` via `trans()`.
- [ ] Keep staff-only strings English if the owner prefers — **confirm with the owner** whether `/admin` should be bilingual or English-only. Default: bilingual for consistency.

### Task D4: Emails + notifications
- [ ] `resources/views/emails/*`, notification `toMail` content, `lang/*/notifications.php`.

Each task: extract → translate → test → commit. Do **not** touch legal-page bodies (Terms/Privacy) — those need professional translation and are tracked separately.

---

## Batch E — Cleanup

### Task E1: Wire domestic search to the Category tree (§1.5.2b)
- [ ] `DomesticMarketplaceService` — replace the interim `product_type` facet with `Category` tree filtering (the tree is fully adopted per 1.5.1). Keep the URL params backward-compatible (redirect old `?product_type=` to the category equivalent via `SlugRedirect` or a param mapper). TDD, commit.

### Task E2: Feature-gate dormant account roles that now have features (§0.7b, partial)
- [ ] `logistics_partner` → gate the fleet resources + logistics directory self-service to it (feature now exists).
- [ ] `carbon_developer` → gate carbon-project self-posting to it (feature exists as a directory; full gate lands with Batch F).
- [ ] `processor` / `artisan` → gate the Transformation Network / artisan-portfolio self-service surfaces.
- [ ] `carbon_buyer` → still nothing to gate; leave documented.
- [ ] Test each gate; commit per role.

### Task E3: Carried-over SEO defects (from GAP_PLAN "Carried-over defects")
- [ ] Species meta descriptions truncate mid-word at ~303 chars on all 52 pages → truncate on a word boundary at ~155 chars (proper meta length) in the species presenter. Test one long-description species.
- [ ] Supplier JSON-LD publishes placeholder `*.example` contact data beside a "Verified Exporter" claim → suppress any `*.example` / obviously-fake contact from the schema output (or gate schema `contactPoint` on real data). Test with a seeded placeholder-contact company.
- [ ] `og:type` hardcoded `website` → `article` on article pages, `product` on product pages, `profile` on supplier pages.
- [ ] Duplicate `<h1>` on the 8 affected pages (homepage has two that differ) → one `<h1>` per page; demote the second to `<h2>` / `<p>`.
- [ ] Each fix gets a focused assertion in the relevant `*Test.php`; commit.

### Task E4: Document-consumer migration decision (§0.1b)
- [ ] **Decision task, not a build task.** Either (a) execute the 24+4 consumer migration (`CompanyDocument` / `OrderDocument` → the polymorphic `documents` store — the backfill command already exists and is tested), removing the legacy tables and models; or (b) write a short ADR in `docs/architecture/` accepting the split permanently, with the reasoning (two mature, tested subsystems; the polymorphic store is used for new consumers only). Recommend (a) — the split widened again this session with the fleet resources, and a single document store is what §3.2 asks for. If (a): this becomes its own multi-task sub-plan (one consumer group per task, backfill-verify-cutover-drop).

---

## Batch F — Carbon project registry core (§2.6, registry only)

**Explicitly not** carbon-credit trading or lifecycle (§2.7) — that needs registry accreditation.

### Task F1: Carbon project boundary + status machine + public verification
- [ ] **Step 1: Write `tests/Feature/CarbonProjectRegistryTest.php`** — a carbon project stores a valid GeoJSON polygon boundary (invalid rejected on write, reusing `GeoJsonPolygon`); a `CTH-CARB-…` public id is assigned on create; the status machine allows only `draft → submitted → under_review → registered → active` (+ `rejected`, `suspended`); a public `GET /verify/carbon/{publicId}` page renders the project, developer company, boundary map, status, and QR for `active`/`registered` projects and 404s others.
- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement** — mirror `TimberLot` (jsonb `boundary` + `GeoJsonPolygon` validation), `Certificate` (public id + verification token), and the timber-lot passport page. Status as a backed enum with an explicit transition table (mirror `VerificationFlowService`'s discipline). `CarbonProjectQrCodeService` copied from `CertificateQrCodeService`. Exporter-panel resource gated to `carbon_developer` (completes that part of E2).
- [ ] **Step 4: Run — expect PASS**
- [ ] **Step 5: Commit**

---

## Self-review

- **Scope coverage:** every open `docs/GAP_PLAN.md` item is either in a batch above or in the "out of scope — blocked on a decision" table with the blocker named. Nothing is silently dropped.
- **No fabrication:** batches only build against systems that exist. Every place a real external decision is required (error sink, offsite backup, admin-panel-bilingual, PriceBand legal review, document-migration go/no-go) is a **confirm-with-owner** step, not an assumption.
- **Type consistency:** new events register in `RelayOutboxEventsJob::EVENT_MAP`; new exporter resources follow the `CapacityResource` scoping pattern; new QR services copy `ShipmentWaybillQrCodeService`; new hash-chains copy `ChainedActivity`; new shared test helpers go in `tests/Support/`.
- **Verification:** every task ends with the parallel suite green and a commit; every batch ends with a production deploy + browser smoke-test.

## Execution handoff

**Two execution options:**

1. **Subagent-Driven (recommended)** — dispatch a fresh subagent per task, spec-review then code-review between tasks, deploy per batch. Matches how this session has worked throughout.
2. **Inline** — execute tasks in this session with checkpoints per batch.

Batch A should land before B–F start iterating (you cannot safely tune a live platform you cannot observe). B and D carry the most user-visible value and can run in parallel after A.
