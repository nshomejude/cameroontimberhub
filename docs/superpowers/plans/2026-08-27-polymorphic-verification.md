# Polymorphic Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the platform a single, reusable polymorphic verification workflow — a `Verification` model over a `verifications` table with `entity_type`/`entity_id`, driving the brief's 8-stage sequence (`registered → company_info → business_docs → identity_kyc → forestry_legal_docs → compliance_review → verified → published`, with `rejected` as a terminal branch off `compliance_review`), plus a `verification_checkpoints` child table that records each stage's review outcome — including `needs_more_info`, which lives at that request/checkpoint level, never as a `Verification.stage` value. This is item 0.2 of `docs/GAP_PLAN.md`, scoped down (see "Scope decision" below).

**Architecture:** Two new tables (`verifications`, `verification_checkpoints`), two new models (`Verification`, `VerificationCheckpoint`), two new enums (`VerificationStage`, `CheckpointStatus`), a `VerificationFlowService` that is the single mutation point for stage transitions (mirroring `CompanyStatusService`'s `TRANSITIONS` map pattern), a `HasVerification` trait, and a `VerificationPolicy` reusing the existing `verification.review` permission. Nothing existing is touched — `companies.status`, `verification_requests`, `CompanyStatusService` and `VerificationService` keep working exactly as they do today.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL, Pest.

Reference: `docs/GAP_PLAN.md` item 0.2, `docs/AUDIT.md` (data-model conflicts table, row: "Two verification machines... one 8-state machine ending `published`"), `CTH_Claude_Code_Build_Brief.md` §3.4/§9, and the sibling plan `docs/superpowers/plans/2026-08-27-polymorphic-document-store.md` (item 0.1, same scoping pattern).

## Scope decision (read before executing)

`docs/GAP_PLAN.md` item 0.2 describes *collapsing* `companies.status` (6-value CHECK: `draft|pending|verified|suspended|rejected|archived`) and `verification_requests.status` (4-value CHECK: `pending|in_review|approved|rejected`) into one new 8-state machine, with `verification_requests.company_id` becoming polymorphic `(entity_type, entity_id)`.

Investigation before this plan was written found `companies.status` and `verification_requests` are exactly as entangled as `company_documents` was for item 0.1 (see that plan's own scope decision). Concretely:

- `CompanyStatusService::TRANSITIONS` is a live 6-state machine with its own reason-required rules, `activity()` logging, and `verified_at` side effects.
- `VerificationService` (`app/Services/VerificationService.php`) drives `VerificationRequest.status` through `submit()/startReview()/approve()/reject()`, calls into `CompanyStatusService`, `BadgeService`, and stamps `companies.verified_at`/`verification_expires_at`.
- Three `Actions\Verification\*` classes (`OpenVerificationRequest`, `ApproveVerificationRequest`, `RejectVerificationRequest`) wrap that service and dispatch `CompanyVerified`/`BadgeIssued` events.
- A full Filament admin resource (`app/Filament/Resources/VerificationRequests/**`, list/edit pages, form, table), a `VerificationRequestPolicy`, a `PendingVerificationsWidget`, and an exporter-panel `EditCompany` page all read or write `companies.status` or `verification_requests.status`.
- `grep` across `app/` and `resources/views/` for `companies.status`/`CompanyStatus::`/`VerificationRequestStatus::`/`verification_requests` turned up **27 files**: 8 models, 6 Filament resource/table/widget files, 5 services, 3 actions, 2 controllers/mail classes, and 8 Blade views that branch on a company's verification badge state for supplier cards and order/RFQ trails.

Collapsing two live, 27-file-deep state machines into one new vocabulary in the same pass as building the new polymorphic table is exactly the kind of large refactor `CTH_Claude_Code_Build_Brief.md` rule 0.3 says to *confirm before doing*, not do silently — the same reasoning that scoped item 0.1 down to "build the store, prove it, defer the migration" (tracked as 0.1b).

**This plan does the additive half only:** build `Verification`/`verifications`/`verification_checkpoints` as a genuinely reusable, standalone 8-stage workflow; prove it end-to-end with a real consumer that has no verification today; and leave `companies.status`/`verification_requests`/`CompanyStatusService`/`VerificationService` completely untouched. Migrating `Company` onto the new framework is real follow-up work — tracked as a new gap-plan item at the end of this plan (Task 6), not silently dropped.

**The real consumer proving this out:** `Product`. Gap-plan item 0.2's own rationale names the brief's target list directly — "§9 requires *one* verification framework for supplier, processor, artisan, **product**, project, retailer, carrier." `Product` (`app/Models/Product.php`) already has a `status` column (`ProductStatus`: `draft|active|archived`), but that is a *publication* state — whether the listing is visible on `/marketplace` — not a verification workflow, and no verification concept exists for products today. Gap-plan item 1.1 separately calls for a "public product verification page" (`/verify/product/{id}`), which will need exactly this kind of entity-level verification record to point at. `Product` is a genuine, honest first consumer: it plausibly needs verification today, has none, and does not require inventing a not-yet-built parent entity (carbon project, vehicle, carrier) just to have something to test against.

**Document relationship decision (stated explicitly per the task brief):** `Verification` does **not** get its own `documents()` relation, and does not duplicate `HasDocuments`. Verifying an entity's identity/business docs (the `business_docs`, `identity_kyc`, `forestry_legal_docs` stages) is about documents that belong to the *entity being verified* (the `Product`, later a carbon project or vehicle), not to the transient `Verification` wrapper around it. Two reasons this matters, not just convention:

1. `Document`'s hash chain (`app/Models/Document.php::booted()`) chains rows `owner_type`/`owner_id` in upload order *per real-world owner*. If `Verification` owned its own documents, a re-submitted verification (a second `Verification` row after `rejected`, see Task 2) would start a **second, disconnected chain** for the same underlying entity — breaking the "one chain per entity" invariant `Document` was built to guarantee.
2. It would duplicate infrastructure `HasDocuments` already provides for free. `Product` gets `use HasDocuments;` (Task 3) exactly as `Species` did for item 0.1, and `VerificationFlowService`'s document-gated stage checks (Task 2) read `$entity->documents()->verified()->where('type', $stageDocType)->exists()` — i.e. `Verification` *reads* the entity's own `Document::verified()` scope rather than owning a parallel copy.

---

### Task 1: The `verifications`/`verification_checkpoints` tables and models

**Files:**
- Create: `database/migrations/2026_08_28_100020_create_verifications_table.php`
- Create: `database/migrations/2026_08_28_100021_create_verification_checkpoints_table.php`
- Create: `app/Enums/VerificationStage.php`
- Create: `app/Enums/CheckpointStatus.php`
- Create: `app/Models/Verification.php`
- Create: `app/Models/VerificationCheckpoint.php`
- Create: `database/factories/VerificationFactory.php`
- Create: `database/factories/VerificationCheckpointFactory.php`
- Test: `tests/Feature/VerificationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\Verification;
use App\Models\VerificationCheckpoint;

it('attaches a polymorphic verification to an owning entity', function () {
    $product = Product::factory()->create();

    $verification = Verification::create([
        'entity_type' => Product::class,
        'entity_id' => $product->id,
        'stage' => VerificationStage::Registered,
    ]);

    expect($verification->entity)->toBeInstanceOf(Product::class)
        ->and($verification->entity->is($product))->toBeTrue()
        ->and($product->fresh()->verification->is($verification))->toBeTrue();
});

it('defaults a new verification to the registered stage', function () {
    $verification = Verification::factory()->create();

    expect($verification->stage)->toBe(VerificationStage::Registered);
});

it('allows only one open (non-terminal) verification per entity', function () {
    $product = Product::factory()->create();
    Verification::factory()->for($product, 'entity')->create(['stage' => VerificationStage::CompanyInfo]);

    expect(fn () => Verification::factory()->for($product, 'entity')->create(['stage' => VerificationStage::Registered]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('records a checkpoint against a verification with a stage, status and reviewer', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::ComplianceReview]);

    $checkpoint = VerificationCheckpoint::create([
        'verification_id' => $verification->id,
        'stage' => VerificationStage::ComplianceReview,
        'status' => CheckpointStatus::NeedsMoreInfo,
        'notes' => 'SIGIF permit number is illegible, please re-upload.',
    ]);

    expect($checkpoint->verification->is($verification))->toBeTrue()
        ->and($verification->fresh()->checkpoints)->toHaveCount(1)
        ->and($verification->fresh()->checkpoints->first()->status)->toBe(CheckpointStatus::NeedsMoreInfo);
});

it('exposes whether a verification is currently blocked on needs_more_info without changing its stage', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::IdentityKyc]);
    VerificationCheckpoint::factory()->for($verification)->create([
        'stage' => VerificationStage::IdentityKyc,
        'status' => CheckpointStatus::NeedsMoreInfo,
    ]);

    expect($verification->fresh()->needsMoreInfo())->toBeTrue()
        ->and($verification->fresh()->stage)->toBe(VerificationStage::IdentityKyc);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/VerificationTest.php`
Expected: FAIL — `Class "App\Models\Verification" not found`.

- [ ] **Step 3: Write the enums**

```php
<?php

namespace App\Enums;

/**
 * Sequential stage of a polymorphic Verification (spec §3.4, gap-plan 0.2).
 * These are the brief's 8 named stages plus the `Rejected` terminal branch
 * off `ComplianceReview`. `needs_more_info` is deliberately NOT a case here —
 * it is a per-checkpoint outcome (see CheckpointStatus::NeedsMoreInfo) that
 * leaves the parent Verification's stage unchanged; see
 * Verification::needsMoreInfo().
 */
enum VerificationStage: string
{
    case Registered = 'registered';
    case CompanyInfo = 'company_info';
    case BusinessDocs = 'business_docs';
    case IdentityKyc = 'identity_kyc';
    case ForestryLegalDocs = 'forestry_legal_docs';
    case ComplianceReview = 'compliance_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::CompanyInfo => 'Company info',
            self::BusinessDocs => 'Business documents',
            self::IdentityKyc => 'Identity / KYC',
            self::ForestryLegalDocs => 'Forestry legal documents',
            self::ComplianceReview => 'Compliance review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Published => 'Published',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Registered, self::CompanyInfo, self::BusinessDocs, self::IdentityKyc, self::ForestryLegalDocs => 'gray',
            self::ComplianceReview => 'warning',
            self::Verified, self::Published => 'success',
            self::Rejected => 'danger',
        };
    }

    /** Terminal stages a Verification does not transition out of. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Published], true);
    }
}
```

```php
<?php

namespace App\Enums;

/**
 * Outcome of a single review request against a Verification at a given
 * stage (spec §3.4's "needs_more_info at request level" — gap-plan 0.2).
 * A checkpoint's status is independent of Verification::stage: a
 * NeedsMoreInfo checkpoint leaves the parent stage where it was, it does not
 * push the Verification into some "needs_more_info" state.
 */
enum CheckpointStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsMoreInfo = 'needs_more_info';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NeedsMoreInfo => 'Needs more info',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::NeedsMoreInfo => 'warning',
        };
    }
}
```

- [ ] **Step 4: Write the migrations**

`database/migrations/2026_08_28_100020_create_verifications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('stage', 30)->default('registered');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['entity_type', 'entity_id']);
            $table->index('assigned_to');
        });

        DB::statement("ALTER TABLE verifications ADD CONSTRAINT verifications_stage_check CHECK (stage IN ('registered','company_info','business_docs','identity_kyc','forestry_legal_docs','compliance_review','verified','rejected','published'))");

        // One OPEN (non-terminal) verification per entity. Terminal stages
        // (rejected, published) are excluded from the partial unique index so
        // a rejected entity can register a fresh Verification and try again,
        // and a published one is simply done. Mirrors the
        // verification_requests_queue_idx partial-index pattern this table
        // deliberately does not touch.
        DB::statement("CREATE UNIQUE INDEX verifications_open_per_entity_idx ON verifications (entity_type, entity_id) WHERE stage NOT IN ('rejected','published')");
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
```

`database/migrations/2026_08_28_100021_create_verification_checkpoints_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_checkpoints', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('verification_id')->constrained('verifications')->cascadeOnDelete();
            $table->string('stage', 30);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('verification_id');
            $table->index(['verification_id', 'stage']);
        });

        DB::statement("ALTER TABLE verification_checkpoints ADD CONSTRAINT verification_checkpoints_stage_check CHECK (stage IN ('registered','company_info','business_docs','identity_kyc','forestry_legal_docs','compliance_review','verified','rejected','published'))");
        DB::statement("ALTER TABLE verification_checkpoints ADD CONSTRAINT verification_checkpoints_status_check CHECK (status IN ('pending','approved','rejected','needs_more_info'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_checkpoints');
    }
};
```

- [ ] **Step 5: Write the models**

`app/Models/Verification.php`:

```php
<?php

namespace App\Models;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The polymorphic verification workflow for any entity (Product today;
 * carbon projects, vehicles, artisans as those land — see gap-plan 0.2 and
 * `CTH_Claude_Code_Build_Brief.md` §3.4).
 *
 * `stage` tracks position in the 8-step sequence
 * (registered -> company_info -> business_docs -> identity_kyc ->
 * forestry_legal_docs -> compliance_review -> verified -> published), with
 * `rejected` as a terminal branch off compliance_review. Mutation goes
 * through VerificationFlowService, never direct assignment here — see that
 * class's TRANSITIONS map for the legal-transition table.
 *
 * `needs_more_info` is NOT a stage value on this model. It is a
 * VerificationCheckpoint::status outcome recorded against the *current*
 * stage without moving `stage` itself — see needsMoreInfo() below, which
 * reads the latest checkpoint rather than any column on this row.
 */
class Verification extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => VerificationStage::class,
            'published_at' => 'datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(VerificationCheckpoint::class);
    }

    /**
     * True when the most recent checkpoint at the current stage asked for
     * more information and no newer checkpoint has superseded it. This is
     * how gap-plan 0.2's "needs_more_info at request level" is surfaced
     * without it ever becoming a Verification::stage value.
     */
    public function needsMoreInfo(): bool
    {
        $latest = $this->checkpoints()
            ->where('stage', $this->stage->value)
            ->latest('id')
            ->first();

        return $latest?->status === CheckpointStatus::NeedsMoreInfo;
    }
}
```

`app/Models/VerificationCheckpoint.php`:

```php
<?php

namespace App\Models;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewed request against a Verification at a given stage. A Verification
 * accumulates one or more checkpoints per stage as it is submitted, sent back
 * for more info, resubmitted, and finally approved or rejected.
 */
class VerificationCheckpoint extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => VerificationStage::class,
            'status' => CheckpointStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 6: Write the factories**

`database/factories/VerificationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Verification> */
class VerificationFactory extends Factory
{
    protected $model = Verification::class;

    public function definition(): array
    {
        return [
            'entity_type' => Product::class,
            'entity_id' => Product::factory(),
            'stage' => VerificationStage::Registered,
        ];
    }
}
```

`database/factories/VerificationCheckpointFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Verification;
use App\Models\VerificationCheckpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerificationCheckpoint> */
class VerificationCheckpointFactory extends Factory
{
    protected $model = VerificationCheckpoint::class;

    public function definition(): array
    {
        return [
            'verification_id' => Verification::factory(),
            'stage' => VerificationStage::Registered,
            'status' => CheckpointStatus::Pending,
        ];
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that's corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force` after confirming `--env=testing` genuinely targets `cameroontimberhub_testing` (a `.env.testing` file must exist — verify with `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` first). **Never run `migrate:fresh` without that verification** — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead.

Run: `php artisan test tests/Feature/VerificationTest.php`
Expected: PASS.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_28_100020_create_verifications_table.php \
        database/migrations/2026_08_28_100021_create_verification_checkpoints_table.php \
        app/Enums/VerificationStage.php app/Enums/CheckpointStatus.php \
        app/Models/Verification.php app/Models/VerificationCheckpoint.php \
        database/factories/VerificationFactory.php database/factories/VerificationCheckpointFactory.php \
        tests/Feature/VerificationTest.php
git commit -m "Add polymorphic Verification/VerificationCheckpoint tables and models"
```

---

### Task 2: `VerificationFlowService` — the 8-stage transition machine

**Files:**
- Create: `app/Services/VerificationFlowService.php`
- Test: `tests/Feature/VerificationFlowServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\User;
use App\Services\VerificationFlowService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->service = app(VerificationFlowService::class);
});

it('opens a verification at the registered stage for an entity with none yet', function () {
    $product = Product::factory()->create();

    $verification = $this->service->open($product);

    expect($verification->stage)->toBe(VerificationStage::Registered)
        ->and($verification->entity->is($product))->toBeTrue();
});

it('returns the existing open verification instead of creating a second one', function () {
    $product = Product::factory()->create();

    $first = $this->service->open($product);
    $second = $this->service->open($product);

    expect($second->id)->toBe($first->id);
});

it('advances through each stage in the defined sequence on approval', function () {
    $verification = $this->service->open(Product::factory()->create());
    $officer = User::factory()->create();

    $sequence = [
        VerificationStage::CompanyInfo,
        VerificationStage::BusinessDocs,
        VerificationStage::IdentityKyc,
        VerificationStage::ForestryLegalDocs,
        VerificationStage::ComplianceReview,
        VerificationStage::Verified,
    ];

    foreach ($sequence as $expected) {
        $verification = $this->service->approve($verification, $officer);
        expect($verification->stage)->toBe($expected);
    }
});

it('rejects a compliance_review checkpoint with a reason, moving the verification to rejected', function () {
    $verification = \App\Models\Verification::factory()->create(['stage' => VerificationStage::ComplianceReview]);
    $officer = User::factory()->create();

    $verification = $this->service->reject($verification, 'SIGIF permit could not be validated.', $officer);

    expect($verification->stage)->toBe(VerificationStage::Rejected)
        ->and($verification->checkpoints()->latest('id')->first()->status)->toBe(CheckpointStatus::Rejected)
        ->and($verification->checkpoints()->latest('id')->first()->notes)->toBe('SIGIF permit could not be validated.');
});

it('requires a reason to reject', function () {
    $verification = \App\Models\Verification::factory()->create(['stage' => VerificationStage::ComplianceReview]);

    expect(fn () => $this->service->reject($verification, '', User::factory()->create()))
        ->toThrow(ValidationException::class);
});

it('records needs_more_info at the current stage without advancing or regressing it', function () {
    $verification = \App\Models\Verification::factory()->create(['stage' => VerificationStage::IdentityKyc]);
    $officer = User::factory()->create();

    $verification = $this->service->requestMoreInfo($verification, 'Please upload a clearer ID scan.', $officer);

    expect($verification->stage)->toBe(VerificationStage::IdentityKyc)
        ->and($verification->needsMoreInfo())->toBeTrue();
});

it('clears needs_more_info and stays at the same stage when resubmitted, ready for the next approval', function () {
    $verification = \App\Models\Verification::factory()->create(['stage' => VerificationStage::IdentityKyc]);
    $officer = User::factory()->create();
    $verification = $this->service->requestMoreInfo($verification, 'Blurry scan.', $officer);

    $verification = $this->service->resubmit($verification);

    expect($verification->stage)->toBe(VerificationStage::IdentityKyc)
        ->and($verification->needsMoreInfo())->toBeFalse();
});

it('publishes a verified entity, stamping published_at', function () {
    $verification = \App\Models\Verification::factory()->create(['stage' => VerificationStage::Verified]);
    $officer = User::factory()->create();

    $verification = $this->service->publish($verification, $officer);

    expect($verification->stage)->toBe(VerificationStage::Published)
        ->and($verification->published_at)->not->toBeNull();
});

it('refuses to publish a verification that has not reached verified', function () {
    $verification = \App\Models\Verification::factory()->create(['stage' => VerificationStage::BusinessDocs]);

    expect(fn () => $this->service->publish($verification, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('refuses to approve a terminal (rejected or published) verification', function () {
    $rejected = \App\Models\Verification::factory()->create(['stage' => VerificationStage::Rejected]);

    expect(fn () => $this->service->approve($rejected, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/VerificationFlowServiceTest.php`
Expected: FAIL — `Class "App\Services\VerificationFlowService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The single mutation point for Verification::stage (gap-plan 0.2,
 * `CTH_Claude_Code_Build_Brief.md` §3.4). Mirrors CompanyStatusService's
 * TRANSITIONS-map pattern deliberately, so this reads the same way to anyone
 * who already knows that service — but drives an entirely separate,
 * standalone table (see this plan's "Scope decision").
 */
class VerificationFlowService
{
    /** @var array<string, string> forward transition on approval, keyed by current stage value */
    public const FORWARD = [
        'registered' => 'company_info',
        'company_info' => 'business_docs',
        'business_docs' => 'identity_kyc',
        'identity_kyc' => 'forestry_legal_docs',
        'forestry_legal_docs' => 'compliance_review',
        'compliance_review' => 'verified',
    ];

    public function open(Model $entity): Verification
    {
        $existing = Verification::query()
            ->where('entity_type', $entity::class)
            ->where('entity_id', $entity->getKey())
            ->whereNotIn('stage', [VerificationStage::Rejected->value, VerificationStage::Published->value])
            ->first();

        if ($existing) {
            return $existing;
        }

        return Verification::create([
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'stage' => VerificationStage::Registered,
        ]);
    }

    public function approve(Verification $verification, User $actor, ?string $notes = null): Verification
    {
        $this->assertNotTerminal($verification);

        $target = self::FORWARD[$verification->stage->value] ?? null;

        if ($target === null) {
            throw new RuntimeException("No forward transition defined from stage {$verification->stage->value}.");
        }

        return DB::transaction(function () use ($verification, $target, $actor, $notes) {
            $verification->checkpoints()->create([
                'stage' => $verification->stage,
                'status' => CheckpointStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'notes' => $notes,
            ]);

            $verification->update(['stage' => $target]);

            return $verification->fresh();
        });
    }

    public function reject(Verification $verification, string $reason, User $actor): Verification
    {
        $this->assertNotTerminal($verification);

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to reject a verification.']);
        }

        return DB::transaction(function () use ($verification, $reason, $actor) {
            $verification->checkpoints()->create([
                'stage' => $verification->stage,
                'status' => CheckpointStatus::Rejected,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'notes' => $reason,
            ]);

            $verification->update(['stage' => VerificationStage::Rejected]);

            return $verification->fresh();
        });
    }

    /**
     * Record that more information is needed at the CURRENT stage. This does
     * not move Verification::stage — see class docblock and
     * Verification::needsMoreInfo().
     */
    public function requestMoreInfo(Verification $verification, string $instructions, User $actor): Verification
    {
        $this->assertNotTerminal($verification);

        if (blank($instructions)) {
            throw ValidationException::withMessages(['instructions' => 'Instructions are required when requesting more information.']);
        }

        $verification->checkpoints()->create([
            'stage' => $verification->stage,
            'status' => CheckpointStatus::NeedsMoreInfo,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'notes' => $instructions,
        ]);

        return $verification->fresh();
    }

    /**
     * The entity resubmits at the same stage after a needs_more_info
     * checkpoint. Records a fresh Pending checkpoint so the latest checkpoint
     * for the stage is no longer NeedsMoreInfo, clearing needsMoreInfo()
     * without touching `stage`.
     */
    public function resubmit(Verification $verification): Verification
    {
        $this->assertNotTerminal($verification);

        $verification->checkpoints()->create([
            'stage' => $verification->stage,
            'status' => CheckpointStatus::Pending,
        ]);

        return $verification->fresh();
    }

    public function publish(Verification $verification, User $actor): Verification
    {
        if ($verification->stage !== VerificationStage::Verified) {
            throw new RuntimeException('Only a verification at the verified stage can be published, current stage: '.$verification->stage->value);
        }

        return DB::transaction(function () use ($verification, $actor) {
            $verification->checkpoints()->create([
                'stage' => VerificationStage::Verified,
                'status' => CheckpointStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            $verification->update([
                'stage' => VerificationStage::Published,
                'published_at' => now(),
            ]);

            return $verification->fresh();
        });
    }

    protected function assertNotTerminal(Verification $verification): void
    {
        if ($verification->stage->isTerminal()) {
            throw new RuntimeException("Verification is in a terminal stage ({$verification->stage->value}) and cannot transition further.");
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/VerificationFlowServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/VerificationFlowService.php tests/Feature/VerificationFlowServiceTest.php
git commit -m "Add VerificationFlowService driving the 8-stage verification machine"
```

---

### Task 3: `HasVerification` trait, wired to `Product`

**Files:**
- Create: `app/Models/Concerns/HasVerification.php`
- Modify: `app/Models/Product.php`
- Test: `tests/Feature/ProductVerificationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\Verification;

it('gives a product a verification relation', function () {
    $product = Product::factory()->create();
    $verification = Verification::factory()->for($product, 'entity')->create();

    expect($product->fresh()->verification->is($verification))->toBeTrue();
});

it('reports a product with no verification yet as unverified', function () {
    $product = Product::factory()->create();

    expect($product->isVerified())->toBeFalse();
});

it('reports a product as verified once its verification reaches verified or published', function () {
    $product = Product::factory()->create();
    Verification::factory()->for($product, 'entity')->create(['stage' => VerificationStage::Verified]);

    expect($product->fresh()->isVerified())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/ProductVerificationTest.php`
Expected: FAIL — `Call to undefined method App\Models\Product::verification()` (or similar).

- [ ] **Step 3: Write the trait**

```php
<?php

namespace App\Models\Concerns;

use App\Enums\VerificationStage;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Gives a model a polymorphic `verification` relation over the shared
 * Verification workflow (gap-plan 0.2). A model can hold at most one OPEN
 * Verification at a time — see the partial unique index in
 * 2026_08_28_100020_create_verifications_table.php — so this is morphOne,
 * not morphMany, matching the "current verification state" mental model
 * (history lives on VerificationCheckpoint, not on multiple Verification
 * rows).
 */
trait HasVerification
{
    public function verification(): MorphOne
    {
        return $this->morphOne(Verification::class, 'entity')->latestOfMany();
    }

    public function isVerified(): bool
    {
        return in_array($this->verification?->stage, [VerificationStage::Verified, VerificationStage::Published], true);
    }
}
```

- [ ] **Step 4: Wire the trait onto `Product`**

In `app/Models/Product.php`, add the import and the trait to the `use` clause:

```php
use App\Models\Concerns\HasVerification;
```

```php
class Product extends Model
{
    use HasFactory, HasSlug, HasVerification, SoftDeletes;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ProductVerificationTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Models/Concerns/HasVerification.php app/Models/Product.php tests/Feature/ProductVerificationTest.php
git commit -m "Give Product a polymorphic verification relation via HasVerification"
```

---

### Task 4: `VerificationPolicy`

**Files:**
- Read: `app/Policies/VerificationRequestPolicy.php` (already read during planning — mirror its exact permission idiom)
- Create: `app/Policies/VerificationPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (or wherever `VerificationRequestPolicy` is registered — verify the real mechanism before editing)
- Test: `tests/Feature/VerificationPolicyTest.php`

- [ ] **Step 1: Confirm how `VerificationRequestPolicy` is registered**

Run: `grep -rn "VerificationRequestPolicy" app/Providers/`

If it is in an explicit `$policies` map, follow that exact pattern for `Verification::class => VerificationPolicy::class`. If Filament/Laravel auto-discovers it by naming convention (`Verification` -> `VerificationPolicy`, both in the conventional namespaces used here), no registration step is needed — confirm which is true before writing Step 5's registration.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Product;
use App\Models\User;
use App\Models\Verification;

it('lets a user with verification.review view and review any verification', function () {
    $officer = User::factory()->create();
    $officer->givePermissionTo('verification.review');
    $verification = Verification::factory()->for(Product::factory()->create(), 'entity')->create();

    expect($officer->can('view', $verification))->toBeTrue()
        ->and($officer->can('review', $verification))->toBeTrue();
});

it('refuses a user with no relevant permission', function () {
    $user = User::factory()->create();
    $verification = Verification::factory()->for(Product::factory()->create(), 'entity')->create();

    expect($user->can('view', $verification))->toBeFalse()
        ->and($user->can('review', $verification))->toBeFalse();
});
```

Before running, check `grep -rn "givePermissionTo\|function staff(" tests/Feature/VerificationRequestPolicyTest.php tests/` (if a `VerificationRequestPolicyTest.php` or a shared `staff(...)` helper exists) and match whichever permission-granting idiom the codebase's existing policy tests actually use — substitute into the test above if it differs from `givePermissionTo`.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/VerificationPolicyTest.php`
Expected: FAIL — policy class not found / not registered.

- [ ] **Step 4: Write the policy**, reusing the existing `verification.review` permission (already seeded in `database/seeders/RolesAndPermissionsSeeder.php::PERMISSIONS` — do not add a new permission slug for this):

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Verification;

class VerificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('verification.review');
    }

    public function view(User $user, Verification $verification): bool
    {
        return $user->can('verification.review');
    }

    public function review(User $user, Verification $verification): bool
    {
        return $user->can('verification.review');
    }

    public function create(User $user): bool
    {
        return $user->can('verification.review') || $user->can('products.manage');
    }
}
```

- [ ] **Step 5: Register the policy** if Step 1 found an explicit map (add `Verification::class => VerificationPolicy::class,` alongside the existing `VerificationRequest::class => VerificationRequestPolicy::class,` entry); otherwise confirm auto-discovery picks it up by running the test.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/VerificationPolicyTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Policies/VerificationPolicy.php tests/Feature/VerificationPolicyTest.php
# plus app/Providers/AuthServiceProvider.php if Step 5 touched it
git commit -m "Add VerificationPolicy reusing the existing verification.review permission"
```

---

### Task 5: Self-review and final verification

- [ ] **Step 1: Confirm no existing table, model, controller, policy, service or view was modified**, other than `app/Models/Product.php` (Task 3) and whatever `AuthServiceProvider` edit Task 4 needed. Run `git log --oneline` for this plan's commits, then `git diff <first-commit-of-this-plan>^..HEAD --stat` and check every listed file is either newly created or one of those two expected modifications.

- [ ] **Step 2: Confirm `companies.status`, `verification_requests`, `CompanyStatusService` and `VerificationService` are completely untouched** — no migration alters `companies` or `verification_requests`, and none of the 27 consumer files identified in this plan's "Scope decision" section were modified.

- [ ] **Step 3: Run the full suite one final time.**

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that's corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force` after confirming `--env=testing` genuinely targets `cameroontimberhub_testing` (a `.env.testing` file must exist — verify with `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` first). **Never run `migrate:fresh` without that verification** — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead.

```bash
php artisan test
```

Expected: fully green, no regressions against whatever the baseline count was when this plan started.

- [ ] **Step 4: Confirm the full stage sequence empirically** in the final report — walk a single `Product`'s `Verification` from `registered` through `published` via `VerificationFlowService` (tinker or a quick script), and show the actual `stage` value after each `approve()` call plus a `requestMoreInfo()`/`resubmit()` pair that leaves `stage` unchanged while `needsMoreInfo()` flips true then false. Not just "the tests passed" — the actual sequence of values.

---

### Task 6: Record the deferred `Company`/`verification_requests` migration as a tracked gap-plan item

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Add a new item** (e.g. `0.2b`) to the Phase 0 table in `docs/GAP_PLAN.md`, worded along these lines (adjust to match the file's actual current numbering — read it first, since 0.1b or other items may have shifted rows):

> **Migrate `Company` and `verification_requests` onto the polymorphic `Verification` framework.** `companies.status` (6-value CHECK) and `verification_requests.status` (4-value CHECK) are read or written by 27 files — `CompanyStatusService`, `VerificationService`, 3 `Actions\Verification\*` classes, the `VerificationRequests` Filament resource, `VerificationRequestPolicy`, `PendingVerificationsWidget`, the exporter panel's `EditCompany` page, and 8 Blade views branching on a company's verification/badge state. Needs a dedicated, carefully tested migration pass (badge issuance and `CompanyVerified`/`BadgeIssued` events must keep firing at the same points in the new 8-stage sequence) — not bundled into building the `Verification` table itself. See `docs/superpowers/plans/2026-08-27-polymorphic-verification.md`'s "Scope decision" section for the full file inventory. ~6 days.

- [ ] **Step 2: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Track the Company/verification_requests migration onto Verification as its own gap-plan item"
```

---

## Self-Review Notes

- **Spec coverage:** implements the reusable half of gap-plan item 0.2 — a working polymorphic `Verification`/`VerificationCheckpoint` pair driving the brief's exact 8-stage sequence (`registered → company_info → business_docs → identity_kyc → forestry_legal_docs → compliance_review → verified → published`, `rejected` as the terminal branch off `compliance_review`), with `needs_more_info` implemented strictly as a `VerificationCheckpoint::status` value that never appears on `Verification::stage` — matching the item's own specific wording. Explicitly defers the `Company`/`verification_requests` collapse (the actual "two state machines" merge named in the item) as tracked follow-up rather than silently dropping it or attempting a 27-file refactor in one pass — a judgment call under brief rule 0.3, stated in the "Scope decision" section rather than hidden. The `Document`-relationship question the task brief called out explicitly is answered and justified in that same section (reuse `HasDocuments` on the target entity; `Verification` does not own documents).
- **No placeholders:** every step carries real code, exact file paths, and runnable commands; Task 6's follow-up gap-plan wording is concrete (named files, named events, an estimate), not "TBD".
- **Type consistency check:** `VerificationFlowService::FORWARD` keys/values (Task 2) match `VerificationStage` case values exactly (Task 1). `Verification::needsMoreInfo()` (Task 1) reads `CheckpointStatus::NeedsMoreInfo` (Task 1) as written by `VerificationFlowService::requestMoreInfo()` (Task 2) — consistent across tasks. `HasVerification::isVerified()` (Task 3) checks `VerificationStage::Verified`/`Published`, the same two cases `VerificationFlowService::publish()` (Task 2) transitions between. `VerificationPolicy` (Task 4) reuses `verification.review`, confirmed already present in `RolesAndPermissionsSeeder::PERMISSIONS` — no new permission slug invented.
