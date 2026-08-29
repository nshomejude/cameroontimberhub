# Persisted Consent Records Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the platform a reusable, polymorphic `consents` table (`Consent` model over `subject_type`/`subject_id`/`purpose`/`scope`/`granted_at`/`revoked_at`/`evidence`), replace the RFQ web wizard's "consent checkbox that is validated and discarded" with a real persisted, revocable record, and prove that revocation genuinely stops the one live data feed that consent already governs — routing an RFQ to verified exporters. This is item 0.4 of `docs/GAP_PLAN.md`.

**Architecture:** One new `consents` table with a polymorphic `subject` relation, a `Consent` model following the exact shape conventions `Document`/`HasDocuments` already established in this codebase (`docs/superpowers/plans/2026-08-27-polymorphic-document-store.md`), a `HasConsents` trait, and a `ConsentPurpose` backed enum for the closed set of purposes the platform actually asks consent for. `IntakeService::createRfq()` — the RFQ intake write path — records a `Consent` row from the wizard's existing checkbox instead of discarding it. `RfqTriageService::route()` — the exporter-routing data feed — refuses to route an RFQ whose consent has been revoked.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL, Pest.

Reference: `docs/GAP_PLAN.md` item 0.4, `docs/AUDIT.md` (§7.6), `app/Models/Document.php`, `app/Models/Concerns/HasDocuments.php`, `app/Models/DocumentAccessLog.php`.

## Scope decision (read before executing)

**What the "RFQ consent checkbox" actually is.** `resources/views/public/rfq/steps/contact.blade.php:44-47` and `resources/views/public/rfq/create.blade.php:131-132` render a required checkbox labelled *"I consent to Cameroon Timber Hub sharing this request with verified exporters and contacting me by email about it."* This is a genuine, two-part GDPR-style consent (data sharing with third-party exporter companies, plus a marketing/operational contact channel) — not a "confirm this is accurate" acknowledgement. `RfqWizard::rules()` (`app/Services/RfqWizard.php:236`) validates it with Laravel's `accepted` rule, and `RfqController::store()` (`app/Http/Controllers/Public/RfqController.php:143-150`) builds the header array passed to `IntakeService::createRfq()` with `Arr::only($data, ['title', 'project_name', 'buyer_name', 'buyer_company', 'buyer_phone', 'incoterm', 'target_amount', 'deadline', 'shipping_port', 'notes'])` plus a handful of normalised fields — `consent` is deliberately not in that allow-list. Neither `rfqs` (`database/migrations/2026_06_22_120010_create_rfqs_table.php`) nor any later `rfqs` migration has a `consent` column. So the gap-plan's claim is confirmed exactly: the value is validated, then genuinely thrown away — it is not persisted anywhere, not even implicitly via a mass-assignable column. This is a real, honest first consumer, not a synthetic one.

**Why this plan does not touch `app/Http/Requests/Api/V1/StoreRfqRequest.php`.** That request's own docblock (lines 14-16) explains it deliberately strips the `consent` rule because "the native client presents its own consent copy" — i.e. the mobile API path never collects this checkbox at all today, so there is no consent value to persist there. Recording a `Consent` row on that path would mean fabricating consent that was never actually given. This plan only wires the web RFQ wizard (`RfqController::store()`), and Task 5 tracks the mobile-consent gap as a new, explicit gap-plan follow-up rather than silently leaving it unmentioned.

**Why `ContactController`/`InquiryController`'s consent checkboxes are out of scope.** Both `app/Http/Controllers/Public/ContactController.php:141` and `app/Http/Controllers/Public/InquiryController.php:30` have their own, separately-worded consent checkboxes ("I consent to be contacted by email about this inquiry"). They are real too, but wiring three separate flows in one plan risks the same kind of silent scope creep the Document plan's Task 6 called out for `company_documents`/`order_documents`. This plan builds the general-purpose `Consent`/`HasConsents` primitive and wires **one** real consumer end-to-end (RFQ); Task 5 records wiring the contact/inquiry forms onto the same table as a tracked follow-up.

**Revocation is proven against a real data feed, not a synthetic one.** `RfqTriageService::route()` (`app/Services/RfqTriageService.php:71-95`) is the literal mechanism that does what the checkbox promises: it shares an approved RFQ with verified exporter companies (creates an `RfqCompany` routing row, a `Lead`, and notifies the exporter's users). Task 4 makes `route()` refuse to create a new routing for an RFQ whose consent has been revoked, with a test asserting a revoked RFQ routes zero companies and an active one routes normally — this is the brief's "revocation must stop a data feed" requirement, proven against the actual feed rather than a stand-in.

**`purpose` is a backed enum, `scope` is structured JSON.** `purpose` is a closed, small set of things this platform can ever ask consent for (today: sharing an RFQ with exporters). An enum with a `CHECK` constraint mirrors `Document`'s `verification_status` (`app/Enums/DocumentVerificationStatus.php`) and prevents typo'd purposes from silently never matching a query. `scope` is a nullable `jsonb` column: §7.6 of the brief describes consent scoped *per driver, per vehicle, per policy* — three different structured keys depending on subject type, which a free-text tag cannot express cleanly, and which the RFQ consumer itself already needs (see Task 3: the checkbox is genuinely two grants bundled into one — data sharing with exporters, and email contact — captured as `scope => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email']` rather than losing that distinction).

**`evidence` mirrors `DocumentAccessLog`'s exact shape.** `database/migrations/2026_06_22_110030_create_document_access_logs_table.php` records `ip_address` (string, 45) and `user_agent` (string, 512) per access event. `Consent.evidence` is a `jsonb` column holding `{ip_address, user_agent, captured_at}` captured at grant time — the same two request-identity fields that log already trusts, plus an explicit timestamp snapshot (redundant with `granted_at` by design, so the evidence blob is self-contained even if someone reads it outside the row).

---

### Task 1: The `consents` table, `ConsentPurpose` enum, and `Consent` model

**Files:**
- Create: `database/migrations/2026_08_27_100010_create_consents_table.php`
- Create: `app/Enums/ConsentPurpose.php`
- Create: `app/Models/Consent.php`
- Create: `database/factories/ConsentFactory.php`
- Test: `tests/Feature/ConsentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Support\Str;

function makeConsentRfq(array $attributes = []): Rfq
{
    return Rfq::create(array_merge([
        'reference_code' => 'RFQ-2026-'.Str::upper(Str::random(5)),
        'buyer_name' => 'Buyer',
        'buyer_email' => 'buyer@acme.test',
        'status' => 'new',
        'visibility' => 'public',
    ], $attributes));
}

it('grants a polymorphic consent to a subject with structured scope and evidence', function () {
    $rfq = makeConsentRfq();

    $consent = Consent::create([
        'subject_type' => Rfq::class,
        'subject_id' => $rfq->id,
        'purpose' => ConsentPurpose::RfqExporterSharing,
        'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
        'granted_at' => now(),
        'evidence' => ['ip_address' => '203.0.113.9', 'user_agent' => 'PestTestAgent/1.0', 'captured_at' => now()->toIso8601String()],
    ]);

    expect($consent->subject)->toBeInstanceOf(Rfq::class)
        ->and($consent->subject->is($rfq))->toBeTrue()
        ->and($consent->purpose)->toBe(ConsentPurpose::RfqExporterSharing)
        ->and($consent->scope)->toBe(['shared_with' => 'verified_exporters', 'contact_channel' => 'email'])
        ->and($consent->evidence['ip_address'])->toBe('203.0.113.9')
        ->and($consent->granted_at)->not->toBeNull()
        ->and($consent->revoked_at)->toBeNull()
        ->and($consent->isActive())->toBeTrue();
});

it('marks a consent revoked and no longer active', function () {
    $consent = Consent::factory()->for(makeConsentRfq(), 'subject')->create();

    expect($consent->isActive())->toBeTrue();

    $consent->revoke();

    expect($consent->fresh()->revoked_at)->not->toBeNull()
        ->and($consent->fresh()->isActive())->toBeFalse();
});

it('scopes to active (non-revoked) consents only', function () {
    $active = Consent::factory()->for(makeConsentRfq(), 'subject')->create();
    $revoked = Consent::factory()->for(makeConsentRfq(), 'subject')->create(['revoked_at' => now()]);

    $found = Consent::query()->active()->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->is($active))->toBeTrue()
        ->and($found->pluck('id'))->not->toContain($revoked->id);
});

it('scopes to a given subject', function () {
    $rfqA = makeConsentRfq();
    $rfqB = makeConsentRfq();
    Consent::factory()->for($rfqA, 'subject')->create();
    Consent::factory()->for($rfqB, 'subject')->create();

    $found = Consent::query()->forSubject($rfqA)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->subject_id)->toBe($rfqA->id);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ConsentTest.php`
Expected: FAIL — `Class "App\Models\Consent" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

/**
 * The closed set of things this platform records consent for. A `Consent`
 * row's `purpose` is always one of these, enforced by both this enum's cast
 * and the `consents_purpose_check` DB constraint (see the migration) so the
 * two cannot drift.
 *
 * Only one case exists today — the RFQ wizard's "share this request with
 * verified exporters and contact me by email" checkbox (see
 * `docs/superpowers/plans/2026-08-27-persisted-consent.md`, Task 3). Add a
 * case here (and to the migration's CHECK constraint) whenever a new
 * consent-bearing flow is wired onto this table — e.g. the contact/inquiry
 * forms' own consent checkboxes, or driver/vehicle telematics consent once
 * §7 logistics entities exist.
 */
enum ConsentPurpose: string
{
    case RfqExporterSharing = 'rfq_exporter_sharing';

    public function label(): string
    {
        return match ($this) {
            self::RfqExporterSharing => 'RFQ shared with verified exporters',
        };
    }
}
```

- [ ] **Step 4: Write the migration**

Verify against the actual schema before finalizing: confirm no table named `consents` already exists (`grep -rl "create_consents_table\|Schema::create('consents'" database/migrations/` — expect no output).

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single, reusable, polymorphic consent ledger for any subject that needs
 * revocable, inspectable consent — the RFQ wizard's "share with verified
 * exporters" checkbox today (see
 * docs/superpowers/plans/2026-08-27-persisted-consent.md); the contact/inquiry
 * forms' own consent checkboxes and per-driver/vehicle/policy telematics
 * consent (brief §7.6) as those flows are wired on or built.
 *
 * `purpose` is a closed enum (App\Enums\ConsentPurpose) backed by the CHECK
 * constraint below, following the same convention `documents.verification_status`
 * already uses (see database/migrations/2026_08_28_100010_create_documents_table.php).
 *
 * `scope` is structured JSON, not a free-text tag: §7.6 needs to say *which*
 * driver, vehicle, or policy a grant covers, and the RFQ consumer already
 * needs to say the checkbox covers two things at once (exporter data sharing
 * + email contact) rather than collapsing them into an untyped string.
 *
 * `evidence` mirrors document_access_logs' ip_address/user_agent shape (see
 * database/migrations/2026_06_22_110030_create_document_access_logs_table.php),
 * captured once at grant time as a self-contained JSON snapshot.
 *
 * Revocation (`revoked_at`) is enforced, not decorative — see
 * Consent::scopeActive() and RfqTriageService::route(), which refuses to
 * route an RFQ whose consent has been revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 40);
            $table->jsonb('scope')->nullable();
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
            $table->index('purpose');
        });

        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing'))");
        DB::statement('CREATE INDEX consents_active_idx ON consents (subject_type, subject_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use App\Enums\ConsentPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A revocable, inspectable consent grant for any polymorphic subject.
 *
 * See database/migrations/2026_08_27_100010_create_consents_table.php for
 * the shape rationale (purpose enum, structured scope, evidence snapshot).
 *
 * Revocation is a first-class, enforced action: `scopeActive()` is the only
 * correct way to ask "does this subject currently have consent", and
 * `RfqTriageService::route()` uses exactly that scope to refuse routing an
 * RFQ whose consent has been revoked — see
 * docs/superpowers/plans/2026-08-27-persisted-consent.md, Task 4.
 */
class Consent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'scope' => 'array',
            'evidence' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject::class)->where('subject_id', $subject->getKey());
    }
}
```

- [ ] **Step 6: Factory**

```php
<?php

namespace Database\Factories;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    protected $model = Consent::class;

    public function definition(): array
    {
        return [
            'subject_type' => Rfq::class,
            'subject_id' => Rfq::factory(),
            'purpose' => ConsentPurpose::RfqExporterSharing,
            'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
            'granted_at' => now(),
            'evidence' => [
                'ip_address' => $this->faker->ipv4(),
                'user_agent' => $this->faker->userAgent(),
                'captured_at' => now()->toIso8601String(),
            ],
        ];
    }
}
```

`Rfq::factory()` already exists in this codebase (used by `tests/Feature/RfqTest.php` and others) — confirm with `php artisan tinker --execute="echo class_exists('Database\Factories\RfqFactory') ? 'yes' : 'no';"` before relying on it; if it does not exist, replace the factory default with `subject_id` left required (no default) and have every test pass `->for($rfq, 'subject')` explicitly instead.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ConsentTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Full suite, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test
vendor/bin/pint --dirty
git add database/migrations/2026_08_27_100010_create_consents_table.php app/Enums/ConsentPurpose.php app/Models/Consent.php database/factories/ConsentFactory.php tests/Feature/ConsentTest.php
git commit -m "Add the polymorphic Consent model: a revocable, inspectable consent ledger"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation, and three other planning agents are working concurrently in sibling worktrees against the same shared testing database. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that's corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force` **only after** confirming `--env=testing` genuinely targets `cameroontimberhub_testing` (a `.env.testing` file must exist — verify with `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` first, and confirm the printed name is `cameroontimberhub_testing`, not the dev database). **Never run `migrate:fresh` without that verification** — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead.

---

### Task 2: `HasConsents` trait and wiring it to `Rfq`

**Files:**
- Create: `app/Models/Concerns/HasConsents.php`
- Modify: `app/Models/Rfq.php`
- Test: `tests/Feature/ConsentTest.php` (extend)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ConsentTest.php`:

```php
it('gives a subject a consents relation and an active-consent check', function () {
    $rfq = makeConsentRfq();
    Consent::factory()->for($rfq, 'subject')->create();
    $revokedRfq = makeConsentRfq();
    Consent::factory()->for($revokedRfq, 'subject')->create(['revoked_at' => now()]);

    expect($rfq->consents)->toHaveCount(1)
        ->and($rfq->hasActiveConsent(ConsentPurpose::RfqExporterSharing))->toBeTrue()
        ->and($revokedRfq->hasActiveConsent(ConsentPurpose::RfqExporterSharing))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ConsentTest.php`
Expected: FAIL — `Call to undefined method App\Models\Rfq::consents()` (or `hasActiveConsent`).

- [ ] **Step 3: Write the trait**

```php
<?php

namespace App\Models\Concerns;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a polymorphic `consents` relation over the shared Consent
 * ledger. Add `use HasConsents;` to any model that needs revocable,
 * inspectable consent — see app/Models/Consent.php for the full contract.
 */
trait HasConsents
{
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'subject');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Consent> */
    public function activeConsents(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->consents()->active()->get();
    }

    public function hasActiveConsent(ConsentPurpose $purpose): bool
    {
        return $this->consents()->active()->where('purpose', $purpose->value)->exists();
    }
}
```

- [ ] **Step 4: Wire it to `Rfq`**

Read `app/Models/Rfq.php` in full first to place the `use` statement and trait alongside the existing `use HasFactory, SoftDeletes;` line (`app/Models/Rfq.php:16`).

```php
use App\Models\Concerns\HasConsents;
```

```php
use HasFactory, SoftDeletes, HasConsents;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ConsentTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Models/Concerns/HasConsents.php app/Models/Rfq.php tests/Feature/ConsentTest.php
git commit -m "Add HasConsents trait and wire it to Rfq"
```

(Same test-environment constraint as Task 1 applies to every `php artisan test` run in this plan — foreground only, one run at a time, `.env.testing` verified before any `migrate:fresh`.)

---

### Task 3: Wire the real first consumer — the RFQ wizard's consent checkbox

**Files:**
- Modify: `app/Services/IntakeService.php:33-61` (`createRfq()`)
- Modify: `app/Http/Controllers/Public/RfqController.php:142-157` (`store()`)
- Test: `tests/Feature/RfqTest.php` (extend)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RfqTest.php` (it already defines `rfqPayload()`, which sends `'consent' => '1'`, and seeds roles in `beforeEach`):

```php
it('persists a Consent row from the wizard checkbox instead of discarding it', function () {
    $this->withServerVariables(['HTTP_USER_AGENT' => 'PestTestAgent/1.0'])
        ->post(route('rfq.store'), rfqPayload());

    $rfq = Rfq::firstOrFail();

    expect($rfq->consents)->toHaveCount(1);

    $consent = $rfq->consents->first();

    expect($consent->purpose)->toBe(\App\Enums\ConsentPurpose::RfqExporterSharing)
        ->and($consent->scope)->toBe(['shared_with' => 'verified_exporters', 'contact_channel' => 'email'])
        ->and($consent->granted_at)->not->toBeNull()
        ->and($consent->revoked_at)->toBeNull()
        ->and($consent->evidence['user_agent'])->toBe('PestTestAgent/1.0')
        ->and($consent->evidence['ip_address'])->not->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/RfqTest.php --filter "persists a Consent row"`
Expected: FAIL — `$rfq->consents` is empty (0, not 1).

- [ ] **Step 3: Modify `IntakeService::createRfq()`**

Read `app/Services/IntakeService.php:1-62` in full first — this changes the transaction body. Add an optional trailing `bool $consentGiven` parameter (defaulting `false`, so the four other call sites — `IntakeRfq::execute()`, `app/Http/Controllers/Api/V1/RfqController.php`, `ChatCommerceService`, `ReorderService` — keep compiling and keep recording no consent, which is accurate: none of those flows collect this checkbox today). Import `App\Enums\ConsentPurpose` at the top of the file.

```php
    public function createRfq(array $header, array $items, ?string $source = null, bool $consentGiven = false): Rfq
    {
        $rfq = DB::transaction(function () use ($header, $items, $source, $consentGiven) {
            // Guests stay guests, but when the address already has an account we
            // bind the RFQ to it so it shows up in that buyer's own screens
            // without needing the signed link.
            $owner = isset($header['buyer_email'])
                ? User::whereRaw('lower(email) = ?', [strtolower(trim($header['buyer_email']))])->first()
                : null;

            $rfq = Rfq::create(array_merge($header, [
                'user_id' => $owner?->getKey(),
                'reference_code' => $this->references->generate(),
                'status' => 'new',
                'visibility' => 'public',
                'ip_address' => request()->ip(),
                'source' => $source,
            ]));

            foreach ($items as $item) {
                $rfq->items()->create($item);
            }

            // The wizard's consent checkbox ("share this request with
            // verified exporters and contact me by email") is validated as
            // `accepted` (RfqWizard::rules()) but historically discarded —
            // see docs/superpowers/plans/2026-08-27-persisted-consent.md.
            // A checked box gets a persisted, revocable Consent row here;
            // RfqTriageService::route() refuses to route an RFQ whose
            // consent has since been revoked.
            if ($consentGiven) {
                $rfq->consents()->create([
                    'purpose' => ConsentPurpose::RfqExporterSharing,
                    'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
                    'granted_at' => now(),
                    'evidence' => [
                        'ip_address' => request()->ip(),
                        'user_agent' => substr((string) request()->userAgent(), 0, 512),
                        'captured_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            return $rfq;
        });

        $rfq->load('items');
        $this->risk->evaluate($rfq);

        $this->sendRfqVerificationMail($rfq);

        return $rfq;
    }
```

- [ ] **Step 4: Modify `RfqController::store()`**

Read `app/Http/Controllers/Public/RfqController.php:105-169` in full first. `$data['consent']` is already present in the validated array (`RfqWizard::rules()` validates it as `accepted`; it is just never forwarded downstream) — pass it as the new trailing argument.

```php
        $rfq = $intake->createRfq(
            array_merge(Arr::only($data, [
                'title', 'project_name', 'buyer_name', 'buyer_company', 'buyer_phone',
                'incoterm', 'target_amount', 'deadline', 'shipping_port', 'notes',
            ]), [
                'buyer_name' => trim($data['buyer_name']),
                'buyer_email' => strtolower(trim($data['buyer_email'])),
                'buyer_country_code' => strtoupper($data['buyer_country_code']),
                'destination_country_code' => strtoupper($data['destination_country_code']),
                'target_currency' => isset($data['target_currency']) ? strtoupper($data['target_currency']) : null,
            ]),
            array_map(fn (array $i) => Arr::only($i, [
                'species_id', 'species_text', 'form', 'grade', 'dimensions', 'quantity', 'unit', 'moisture_content',
            ]), $data['items']),
            $request->input('source', 'request_quote'),
            filled($data['consent'] ?? null),
        );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/RfqTest.php --filter "persists a Consent row"`
Expected: PASS.

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/IntakeService.php app/Http/Controllers/Public/RfqController.php tests/Feature/RfqTest.php
git commit -m "Persist the RFQ wizard's consent checkbox as a Consent record instead of discarding it"
```

---

### Task 4: Enforce revocation — a revoked consent stops exporter routing

**Files:**
- Modify: `app/Services/RfqTriageService.php:71-95` (`route()`)
- Test: `tests/Feature/RfqTest.php` (extend)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RfqTest.php`. Read `tests/Feature/RfqTest.php` in full first for the existing `route()` test (search for `RfqTriageService` and `->route(`) so this new test matches the actors/fixtures the file already sets up (approved RFQ, verified `Company`, admin `User`).

```php
it('refuses to route an RFQ whose consent has been revoked, and routes normally when active', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $company = Company::factory()->create(['status' => \App\Enums\CompanyStatus::Verified]);

    $activeRfq = makeRfq(['status' => \App\Enums\RfqStatus::Approved]);
    $activeRfq->consents()->create([
        'purpose' => \App\Enums\ConsentPurpose::RfqExporterSharing,
        'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
        'granted_at' => now(),
        'evidence' => ['ip_address' => '203.0.113.9', 'user_agent' => 'Test/1.0', 'captured_at' => now()->toIso8601String()],
    ]);

    $revokedRfq = makeRfq(['status' => \App\Enums\RfqStatus::Approved]);
    $revokedConsent = $revokedRfq->consents()->create([
        'purpose' => \App\Enums\ConsentPurpose::RfqExporterSharing,
        'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
        'granted_at' => now()->subDay(),
        'evidence' => ['ip_address' => '203.0.113.9', 'user_agent' => 'Test/1.0', 'captured_at' => now()->subDay()->toIso8601String()],
    ]);
    $revokedConsent->revoke();

    $triage = app(\App\Services\RfqTriageService::class);
    $leads = app(\App\Services\LeadFlowService::class);

    $activeRouted = $triage->route($activeRfq, [$company->id], $admin, $leads);
    $revokedRouted = $triage->route($revokedRfq, [$company->id], $admin, $leads);

    expect($activeRouted)->toBe(1)
        ->and($revokedRouted)->toBe(0)
        ->and(\App\Models\RfqCompany::where('rfq_id', $activeRfq->id)->exists())->toBeTrue()
        ->and(\App\Models\RfqCompany::where('rfq_id', $revokedRfq->id)->exists())->toBeFalse();
});
```

Check the exact `User`/role-assignment idiom this file already uses for an admin actor (search `tests/Feature/RfqTest.php` for how its existing `route()` test builds `$actor`) and match it — the snippet above uses Spatie's `assignRole('admin')` as a placeholder for whatever that file's real convention is; replace it with the file's actual pattern if different.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/RfqTest.php --filter "refuses to route an RFQ whose consent"`
Expected: FAIL — `$revokedRouted` is `1`, not `0` (routing happens unconditionally today).

- [ ] **Step 3: Modify `RfqTriageService::route()`**

Read `app/Services/RfqTriageService.php` in full first (97 lines). Add the guard immediately after the existing `status !== Approved` check, before the loop:

```php
    /**
     * Route an approved RFQ to verified exporters. Idempotent (unique rfq_id,
     * company_id); each new routing creates a lead and notifies the exporter.
     *
     * Refuses to route an RFQ whose consent has been revoked — this is the
     * enforcement half of the persisted Consent ledger (see
     * docs/superpowers/plans/2026-08-27-persisted-consent.md): a revoked
     * "share with verified exporters" consent must stop this feed, not just
     * flip a column nothing reads. An RFQ with no Consent row at all (e.g.
     * one created before this plan shipped, or via a path that does not yet
     * collect consent) is treated as routable, matching today's behaviour —
     * only an explicit revocation blocks routing.
     *
     * @param  list<int>  $companyIds
     */
    public function route(Rfq $rfq, array $companyIds, User $actor, LeadFlowService $leads): int
    {
        if ($rfq->status !== RfqStatus::Approved) {
            throw new RuntimeException('Only approved RFQs can be routed.');
        }

        if ($rfq->consents()->where('purpose', \App\Enums\ConsentPurpose::RfqExporterSharing->value)->exists()
            && ! $rfq->hasActiveConsent(\App\Enums\ConsentPurpose::RfqExporterSharing)) {
            return 0;
        }

        $routed = 0;
        foreach ($companyIds as $companyId) {
            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $companyId],
                ['status' => 'sent', 'routed_by' => $actor->getKey(), 'routed_at' => now()],
            );

            if ($routing->wasRecentlyCreated) {
                $leads->createFromRouting($routing);
                Notification::send($routing->company->users, new RfqRoutedToExporter($rfq));
                $routed++;
            }
        }

        return $routed;
    }
```

Add `use App\Models\Concerns\HasConsents;` is not needed here (the trait is on `Rfq`, not this service) — only confirm `Rfq` already has `use HasConsents;` from Task 2 so `$rfq->consents()`/`hasActiveConsent()` resolve.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/RfqTest.php`
Expected: PASS, including the existing `route()` tests in this file (an RFQ with no Consent row at all must still route — confirm the pre-existing routing test, which uses `makeRfq()` and never creates a Consent, still passes unchanged).

- [ ] **Step 5: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/RfqTriageService.php tests/Feature/RfqTest.php
git commit -m "Refuse to route an RFQ to exporters once its consent is revoked"
```

---

### Task 5: Self-review, final verification, and tracking the deferred consumers

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Confirm no unrelated file was touched.** Run `git log --oneline` for this plan's commits and `git diff <first-commit>^..HEAD --stat`. Every touched file should be one of: the new migration/enum/model/factory/test files from Task 1, `app/Models/Concerns/HasConsents.php` + `app/Models/Rfq.php` (Task 2), `app/Services/IntakeService.php` + `app/Http/Controllers/Public/RfqController.php` (Task 3), `app/Services/RfqTriageService.php` (Task 4), plus `docs/GAP_PLAN.md` from this task. Confirm `app/Http/Requests/Api/V1/StoreRfqRequest.php`, `ContactController.php`, and `InquiryController.php` are untouched — the Scope decision above explains why.

- [ ] **Step 2: Confirm revocation is empirically enforced**, not just unit-tested against a mock. In the final report, quote the actual `$activeRouted`/`$revokedRouted` values from Task 4's test run (`1` and `0`) and confirm `RfqCompany::where('rfq_id', $revokedRfq->id)->exists()` was actually asserted false, not merely that the test passed.

- [ ] **Step 3: Run the full suite one final time.**

```bash
php artisan test
```

Expected: fully green, no regressions against whatever the baseline count was when this plan started. (Same test-environment constraint as Task 1: foreground only, `.env.testing` verified before any `migrate:fresh`.)

- [ ] **Step 4: Add two new gap-plan follow-up items**, worded along these lines (adjust numbering to match the file's actual current state — read `docs/GAP_PLAN.md` first):

> **0.4b — Wire the contact/inquiry forms' consent checkboxes onto the `Consent` ledger.** `ContactController::store()` and `InquiryController::store()` each validate their own `consent` checkbox (`app/Http/Controllers/Public/ContactController.php:141`, `app/Http/Controllers/Public/InquiryController.php:30`) and discard it exactly as the RFQ wizard did before item 0.4. Same fix, different subject (`CompanyInquiry` for the inquiry form; the contact form has no persisted subject today — decide whether it needs one, or whether `Consent` should support a `null` subject for "no entity to attach to yet" flows). ~1 day.
>
> **0.4c — Mobile API RFQ consent.** `StoreRfqRequest` (`app/Http/Requests/Api/V1/StoreRfqRequest.php:14-16`) strips the `consent` rule entirely because "the native client presents its own consent copy" — meaning no consent is collected or recorded for API-created RFQs today, and `IntakeService::createRfq()`'s new `$consentGiven` parameter (item 0.4) defaults `false` on that path. Requires the mobile client to actually submit a consent flag before this can be wired without fabricating consent that was never given. ~0.5 day, blocked on mobile client work.

- [ ] **Step 5: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Track the contact/inquiry and mobile-API consent follow-ups as their own gap-plan items"
```

---

## Self-Review Notes

- **Spec coverage:** the `consents` table matches the gap-plan's stated columns exactly (`subject_type, subject_id, purpose, scope, granted_at, revoked_at, evidence`, Task 1). Revocation is enforced, not decorative (Task 4, proven against the real exporter-routing feed). `purpose`/`scope` are explicit, justified judgment calls (Scope decision section) rather than defaults. The RFQ checkbox was verified as genuinely consent-shaped (not a "confirm accuracy" checkbox) before being adopted as the first real consumer, and the two flows deliberately left unwired (contact/inquiry forms, mobile API) are tracked as new gap-plan items rather than silently dropped, mirroring the Document plan's Task 6.
- **No placeholders:** every step has real code, exact file paths and line ranges, and runnable test/verification commands. The one open decision (Step 1's `Rfq::factory()` existence check in Task 1) states its exact fallback rather than leaving a TODO.
- **Type consistency:** `Consent::scopeActive()`/`scopeForSubject()` (Task 1) are the exact methods `HasConsents::activeConsents()`/`hasActiveConsent()` (Task 2) and `RfqTriageService::route()` (Task 4) call. `ConsentPurpose::RfqExporterSharing` is the one case defined in Task 1 and is the only value used by Task 3 (creation) and Task 4 (the routing guard) — verified consistent across tasks. `IntakeService::createRfq()`'s new `bool $consentGiven = false` parameter is additive and every existing call site (`IntakeRfq::execute()`, the API `RfqController`, `ChatCommerceService`, `ReorderService`) keeps compiling unchanged, each correctly recording no consent since none of those flows collect the checkbox.
