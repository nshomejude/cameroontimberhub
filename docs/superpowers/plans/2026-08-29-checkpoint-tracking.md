# Checkpoint Tracking Implementation Plan (gap-plan 1.5.11)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a generic, polymorphic `CheckpointUpdate` primitive (manual checkpoint tracking) plus a public token-based tracking page, so any future "trackable" model (Shipment, once 1.5.10 lands) can attach a checkpoint history without this code depending on Shipment existing.

**Architecture:** Mirror the exact `Consent`/`HasConsents` polymorphic pattern (`trackable_type`/`trackable_id`, a trait) for internal recording, and mirror the exact `ReceiptVerifier`/`ReceiptVerificationController` public-token pattern (high-entropy `tracking_token`, throttled `/track/{token}` route, allow-list payload, generic non-oracle failure) for public disclosure. No `Shipment` file is touched or referenced — a test-fixture model is used to prove the polymorphic mechanism end-to-end.

**Tech Stack:** Laravel 13 migrations/Eloquent, Pest/PHPUnit feature tests, Blade view, `Str::random` token generation, `documents`-style private disk convention for photo storage (reusing the existing `documents` filesystem disk, not a new one).

> **Amendment (during implementation):** every occurrence of `CheckpointStatus` below is actually implemented as `App\Enums\TrackingCheckpointStatus`. Mid-implementation this plan discovered a pre-existing, unrelated `App\Enums\CheckpointStatus` (Verification review-checkpoint outcomes: Pending/Approved/Rejected/NeedsMoreInfo, used by `VerificationCheckpoint`/`VerificationFlowService`) and renamed this feature's enum to avoid the collision. Code and tests use `TrackingCheckpointStatus`; this document's task bodies were written before the rename and still say `CheckpointStatus` in places — read that as `TrackingCheckpointStatus`.

---

## Scope Decision

1.5.10 (`Shipment`) is being built concurrently by a different agent and may not exist yet. Rather than block, `CheckpointUpdate` is built as a **generic polymorphic primitive**, exactly the way `Consent` is generic over `subject_type`/`subject_id` rather than hard-wired to Rfq. The column names are `trackable_type`/`trackable_id` and the trait is `HasCheckpointUpdates`. This is proven end-to-end in `tests/Feature/CheckpointUpdateTest.php` by attaching checkpoints to `Company` (an existing, stable model already used as a factory root elsewhere), **not** to `Shipment`.

**Explicit follow-up, not done here:** once 1.5.10 ships `App\Models\Shipment`, that model needs `use HasCheckpointUpdates;` added to it. That one-line integration is intentionally left out of this plan's scope — it belongs to whoever finishes 1.5.10/wires 1.5.11 to it, and is noted as a tracked follow-up in `docs/GAP_PLAN.md`'s 1.5.11 row.

**Status values** (`checkpoint_updates.status`): a closed set validated at the application layer (not a DB enum, to avoid a migration edit when a new status is needed later) — `dispatched`, `in_transit`, `delayed`, `delivered`. Documented as `App\Enums\CheckpointStatus`.

**Location/GPS**: `location` is free text (e.g. "Douala Port, Gate 3") since "no telematics yet" means there is no device feed. `latitude`/`longitude` are nullable `decimal(10,7)`/`decimal(10,7)` columns a human optionally fills in from their phone's GPS at the moment they record the checkpoint — not a live-tracked feed.

**Photo storage**: reuses the existing private `documents` disk (see `config/filesystems.php`, already used by `Document`/`CompanyDocument`/`OrderDocument`) rather than inventing a new disk. `photo_path` is nullable; when present it is a path on that disk. The public tracking page never serves the raw disk file — that mirrors `Document`'s "never web-served directly" discipline — so the public payload only ever exposes a boolean `has_photo`, keeping this plan free of a new public file-serving route (out of scope; a signed download route is a natural, separately-planned follow-up once a real consumer needs to actually display the photo publicly).

**Token discipline**: `tracking_token` is a separate high-entropy column (`Str::random(48)`, mirroring `Certificate::verification_token` generation in `CertificateService`), never the DB id. The public controller redirects `/track/{token}` into a plain `/track` page render (mirroring `ReceiptVerificationController::token()`) so the token is not baked into the rendered page's URL, and a failed lookup is indistinguishable from any other failed lookup (generic "not found").

## File Structure

- `database/migrations/2026_08_29_150010_create_checkpoint_updates_table.php` — new table.
- `app/Enums/CheckpointStatus.php` — closed status enum with `label()`.
- `app/Models/CheckpointUpdate.php` — the model (`trackable()` morphTo, `scopeForTrackable`, `scopeForToken`, casts).
- `app/Models/Concerns/HasCheckpointUpdates.php` — trait for future consumers (`checkpointUpdates()` morphMany, `latestCheckpoint()`).
- `app/Services/CheckpointTracker.php` — the public-lookup/disclosure boundary, mirroring `ReceiptVerifier` (`findByToken`, `recordCheck`, `publicPayload`).
- `app/Http/Controllers/Public/CheckpointTrackingController.php` — public `/track/{token}` controller (`token()` method only — no search-by-number form; a tracking token is not something a human types from memory, unlike a receipt number).
- `resources/views/public/tracking/show.blade.php` — public checkpoint history view.
- `database/factories/CheckpointUpdateFactory.php` — factory (defaults to a `Company` trackable, since that is the only stable existing model this plan is allowed to touch as a fixture).
- `routes/web.php` — add the `/track/{token}` route, throttled.
- `app/Providers/AppServiceProvider.php` — add a `checkpoint-track` rate limiter, mirroring `receipt-verify`.
- `tests/Feature/CheckpointUpdateTest.php` — full TDD coverage.

No existing model file in the "do not touch" list is modified. `Company` is used only as a *fixture* in the test/factory (attaching a `MorphMany` via the trait requires no edit to `Company.php` itself — the trait is applied ad hoc in the test via an anonymous/local test double, see Task 5).

Actually — a trait can only be "used" by editing the consuming class. Since `Company.php` is off-limits, the test will **not** call `Company::checkpointUpdates()`. Instead the test proves the polymorphic mechanism by writing rows directly with `trackable_type => Company::class` / `trackable_id => $company->id` and asserting `CheckpointUpdate::forTrackable($company)->get()` resolves them, and separately exercises `HasCheckpointUpdates` against a small trait-consuming test fixture class declared inside the test file itself (an anonymous-model-free, in-file class extending `Illuminate\Database\Eloquent\Model` is not viable for a real table, so instead the trait is unit-tested by asserting its `checkpointUpdates()` method builds the exact same relation as `CheckpointUpdate::morphTo`/`morphMany` would — via a tiny throwaway model class defined in the test file backed by the `companies` table). This keeps `Company.php` untouched while still proving the trait's relation-building code executes correctly.

## Task 1: Migration + Enum

**Files:**
- Create: `database/migrations/2026_08_29_150010_create_checkpoint_updates_table.php`
- Create: `app/Enums/CheckpointStatus.php`
- Test: `tests/Feature/CheckpointUpdateTest.php` (new file, first test)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a checkpoint update row with a polymorphic trackable', function () {
    $company = Company::factory()->create();

    $checkpoint = CheckpointUpdate::create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => str()->random(48),
        'status' => CheckpointStatus::Dispatched->value,
        'location' => 'Douala Port, Gate 3',
        'latitude' => 4.0483,
        'longitude' => 9.7043,
        'notes' => 'Left the warehouse on schedule.',
    ]);

    expect($checkpoint->exists)->toBeTrue()
        ->and($checkpoint->status)->toBe(CheckpointStatus::Dispatched)
        ->and((float) $checkpoint->latitude)->toBe(4.0483)
        ->and($checkpoint->trackable_type)->toBe(Company::class)
        ->and($checkpoint->trackable_id)->toBe($company->id);
});
```

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=checkpoint_update tests/Feature/CheckpointUpdateTest.php` (see Task 0 lock protocol below for how to safely run this).
Expected: FAIL — table `checkpoint_updates` / class `CheckpointUpdate` / enum do not exist.

- [ ] **Step 2: Create the enum**

```php
<?php

namespace App\Enums;

/**
 * A closed set of manual checkpoint statuses (gap-plan 1.5.11: "Manual
 * checkpoint tracking ... No telematics yet"). Kept as an application-level
 * enum rather than a DB CHECK constraint enum so a new status can be added
 * without a migration, following the same reasoning as this table's other
 * "no device feed" columns.
 */
enum CheckpointStatus: string
{
    case Dispatched = 'dispatched';
    case InTransit = 'in_transit';
    case Delayed = 'delayed';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Dispatched => 'Dispatched',
            self::InTransit => 'In transit',
            self::Delayed => 'Delayed',
            self::Delivered => 'Delivered',
        };
    }
}
```

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual checkpoint tracking (gap-plan 1.5.11). Generic and polymorphic over
 * `trackable_type`/`trackable_id` — mirroring `consents.subject_type`/
 * `subject_id` (see 2026_08_27_100010_create_consents_table.php) — because
 * the real consumer (`Shipment`, gap-plan 1.5.10) is being built concurrently
 * and may not exist yet. Shipment becomes just one future trackable via
 * `HasCheckpointUpdates`; wiring it up is an explicit follow-up (see
 * docs/superpowers/plans/2026-08-29-checkpoint-tracking.md, Scope Decision).
 *
 * `tracking_token` is a separate high-entropy public identifier — never the
 * `id` — following the exact convention of `certificates.verification_token`
 * and `receipts.verification_token`.
 *
 * `latitude`/`longitude` are nullable: "no telematics yet" means there is no
 * device feed, only a human optionally attaching their phone's GPS position
 * at the moment they record a checkpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkpoint_updates', function (Blueprint $table) {
            $table->id();
            $table->string('trackable_type', 120);
            $table->unsignedBigInteger('trackable_id');
            $table->string('tracking_token', 64)->unique();
            $table->string('status', 40);
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('photo_path', 512)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trackable_type', 'trackable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkpoint_updates');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/CheckpointUpdate.php`:

```php
<?php

namespace App\Models;

use App\Enums\CheckpointStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One manually recorded checkpoint for any polymorphic `trackable` (gap-plan
 * 1.5.11). See database/migrations/2026_08_29_150010_create_checkpoint_updates_table.php
 * for the shape rationale and docs/superpowers/plans/2026-08-29-checkpoint-tracking.md
 * for the Shipment-wiring follow-up.
 */
class CheckpointUpdate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CheckpointStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeForTrackable(Builder $query, Model $trackable): Builder
    {
        return $query->where('trackable_type', $trackable::class)->where('trackable_id', $trackable->getKey());
    }

    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('tracking_token', $token);
    }
}
```

- [ ] **Step 5: Run the migration and test**

Follow the Test-Run Lock protocol (see "Shared Worktree Coordination" below) for every command in this plan that touches the database or runs `artisan test`. Once the lock is held:

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate --env=testing
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/CheckpointUpdateTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```
git add database/migrations/2026_08_29_150010_create_checkpoint_updates_table.php app/Enums/CheckpointStatus.php app/Models/CheckpointUpdate.php tests/Feature/CheckpointUpdateTest.php
git commit -m "feat: add checkpoint_updates table, CheckpointStatus enum, CheckpointUpdate model"
```

## Task 2: Factory + HasCheckpointUpdates trait

**Files:**
- Create: `database/factories/CheckpointUpdateFactory.php`
- Create: `app/Models/Concerns/HasCheckpointUpdates.php`
- Modify: `tests/Feature/CheckpointUpdateTest.php`

- [ ] **Step 1: Write the failing test (append to the same file)**

```php
it('exposes checkpointUpdates and latestCheckpoint via the HasCheckpointUpdates trait', function () {
    $trackable = new class extends \Illuminate\Database\Eloquent\Model {
        use \App\Models\Concerns\HasCheckpointUpdates;

        protected $table = 'companies';

        protected $guarded = ['id'];
    };

    $company = Company::factory()->create();
    $subject = $trackable::query()->find($company->id);

    CheckpointUpdate::factory()->for($subject, 'trackable')->create([
        'status' => CheckpointStatus::Dispatched->value,
        'created_at' => now()->subHour(),
    ]);
    $latest = CheckpointUpdate::factory()->for($subject, 'trackable')->create([
        'status' => CheckpointStatus::InTransit->value,
    ]);

    expect($subject->checkpointUpdates()->count())->toBe(2)
        ->and($subject->latestCheckpoint()->id)->toBe($latest->id);
});
```

Run the test.
Expected: FAIL — `CheckpointUpdate::factory()` / `HasCheckpointUpdates` do not exist.

- [ ] **Step 2: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Enums\CheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckpointUpdate>
 */
class CheckpointUpdateFactory extends Factory
{
    protected $model = CheckpointUpdate::class;

    public function definition(): array
    {
        return [
            'trackable_type' => Company::class,
            'trackable_id' => Company::factory(),
            'tracking_token' => str()->random(48),
            'status' => CheckpointStatus::Dispatched->value,
            'location' => $this->faker->city(),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
            'photo_path' => null,
            'notes' => $this->faker->sentence(),
            'recorded_by' => null,
        ];
    }
}
```

- [ ] **Step 3: Create the trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\CheckpointUpdate;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a polymorphic `checkpointUpdates` relation over the shared
 * CheckpointUpdate ledger (gap-plan 1.5.11). Add `use HasCheckpointUpdates;`
 * to any model that needs a manual checkpoint history — see
 * app/Models/CheckpointUpdate.php for the full contract. `Shipment`
 * (gap-plan 1.5.10) is the intended first real consumer; wiring it up is a
 * tracked follow-up, not done as part of this trait's introduction — see
 * docs/superpowers/plans/2026-08-29-checkpoint-tracking.md.
 */
trait HasCheckpointUpdates
{
    public function checkpointUpdates(): MorphMany
    {
        return $this->morphMany(CheckpointUpdate::class, 'trackable');
    }

    public function latestCheckpoint(): ?CheckpointUpdate
    {
        return $this->checkpointUpdates()->latest('created_at')->first();
    }
}
```

- [ ] **Step 4: Run the test**

Expected: PASS.

- [ ] **Step 5: Commit**

```
git add database/factories/CheckpointUpdateFactory.php app/Models/Concerns/HasCheckpointUpdates.php tests/Feature/CheckpointUpdateTest.php
git commit -m "feat: add CheckpointUpdate factory and HasCheckpointUpdates trait"
```

## Task 3: CheckpointTracker service (public lookup + disclosure boundary)

**Files:**
- Create: `app/Services/CheckpointTracker.php`
- Modify: `tests/Feature/CheckpointUpdateTest.php`

- [ ] **Step 1: Write the failing test**

```php
use App\Services\CheckpointTracker;

it('finds a trackable by tracking token and returns an ordered public payload', function () {
    $company = Company::factory()->create();

    CheckpointUpdate::factory()->create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => 'shared-token-abc',
        'status' => CheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
        'created_at' => now()->subHours(2),
    ]);
    CheckpointUpdate::factory()->create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => 'shared-token-abc',
        'status' => CheckpointStatus::InTransit->value,
        'location' => 'Yaounde Hub',
        'photo_path' => 'checkpoints/photo.jpg',
        'created_at' => now()->subHour(),
    ]);

    $tracker = app(CheckpointTracker::class);

    $history = $tracker->findByToken('shared-token-abc');
    expect($history)->toHaveCount(2);

    $payload = $tracker->publicPayload($history);

    expect($payload)->toHaveCount(2)
        ->and($payload[0]['status'])->toBe('Dispatched')
        ->and($payload[1]['status'])->toBe('In transit')
        ->and($payload[1]['has_photo'])->toBeTrue()
        ->and($payload[0]['has_photo'])->toBeFalse()
        ->and($payload[0])->not->toHaveKey('photo_path')
        ->and($payload[0])->not->toHaveKey('id')
        ->and($payload[0])->not->toHaveKey('trackable_id');
});

it('returns an empty collection for an unknown tracking token', function () {
    $tracker = app(CheckpointTracker::class);

    expect($tracker->findByToken('does-not-exist'))->toHaveCount(0);
});
```

Run the test.
Expected: FAIL — `CheckpointTracker` does not exist.

- [ ] **Step 2: Create the service**

```php
<?php

namespace App\Services;

use App\Models\CheckpointUpdate;
use Illuminate\Support\Collection;

/**
 * The public checkpoint-tracking boundary (gap-plan 1.5.11), mirroring
 * app/Services/ReceiptVerifier.php exactly: a token lookup that is either
 * an exact match or nothing (no partial/oracle-able search), and a
 * hand-written allow-list of what a stranger holding the link is told.
 *
 * A tracking token is shared by every checkpoint row recorded for the same
 * trackable+shipment-of-updates, so a lookup returns the whole ordered
 * history, not a single row.
 */
class CheckpointTracker
{
    /** @return Collection<int, CheckpointUpdate> */
    public function findByToken(string $token): Collection
    {
        $token = trim($token);

        if ($token === '') {
            return collect();
        }

        return CheckpointUpdate::forToken($token)->oldest('created_at')->get();
    }

    /**
     * The complete set of facts a public visitor is given per checkpoint.
     * Deliberately excludes `id`, `trackable_type`/`trackable_id` (internal
     * identity), `photo_path` (the disk path is never disclosed — only
     * whether a photo exists), `recorded_by`, and raw lat/long precision
     * beyond what `location` already conveys.
     *
     * @param  Collection<int, CheckpointUpdate>  $history
     * @return array<int, array<string, mixed>>
     */
    public function publicPayload(Collection $history): array
    {
        return $history->map(fn (CheckpointUpdate $checkpoint) => [
            'status' => $checkpoint->status->label(),
            'location' => $checkpoint->location,
            'notes' => $checkpoint->notes,
            'has_photo' => $checkpoint->photo_path !== null,
            'recorded_at' => $checkpoint->created_at,
        ])->values()->all();
    }
}
```

- [ ] **Step 3: Run the test**

Expected: PASS.

- [ ] **Step 4: Commit**

```
git add app/Services/CheckpointTracker.php tests/Feature/CheckpointUpdateTest.php
git commit -m "feat: add CheckpointTracker public lookup/disclosure service"
```

## Task 4: Public `/track/{token}` route, controller, view

**Files:**
- Create: `app/Http/Controllers/Public/CheckpointTrackingController.php`
- Create: `resources/views/public/tracking/show.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `tests/Feature/CheckpointUpdateTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('shows checkpoint history on the public tracking page for a valid token', function () {
    $company = Company::factory()->create();

    CheckpointUpdate::factory()->create([
        'trackable_type' => Company::class,
        'trackable_id' => $company->id,
        'tracking_token' => 'public-track-token-1',
        'status' => CheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]);

    $response = $this->get('/track/public-track-token-1');

    $response->assertOk();
    $response->assertSee('Dispatched');
    $response->assertSee('Douala Port');
});

it('shows a generic not-found message for an unknown tracking token', function () {
    $response = $this->get('/track/does-not-exist-token');

    $response->assertOk();
    $response->assertSee('No tracking information', escape: false);
});
```

Run the test.
Expected: FAIL — route `/track/{token}` does not exist (404).

- [ ] **Step 2: Add the rate limiter**

Modify `app/Providers/AppServiceProvider.php` — add immediately after the `certificate-verify` limiter block (around line 62):

```php
        // Public checkpoint tracking, same reasoning as receipt-verify /
        // certificate-verify: open to anyone holding the link, so budgeted
        // per-IP against token-grinding.
        RateLimiter::for('checkpoint-track', fn (Request $request) => [
            Limit::perMinute(10)->by('checkpoint-track-ip:'.$request->ip()),
            Limit::perHour(60)->by('checkpoint-track-ip-hour:'.$request->ip()),
        ]);
```

- [ ] **Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\CheckpointTracker;
use Illuminate\View\View;

/**
 * The public checkpoint-tracking page (gap-plan 1.5.11). Mirrors
 * ReceiptVerificationController::token() — a bare token in the URL is looked
 * up and rendered directly (there is no printed "tracking number" to type by
 * hand the way there is a receipt number, so there is no separate search
 * form). Every fact rendered comes from CheckpointTracker::publicPayload(),
 * an allow-list.
 */
class CheckpointTrackingController extends Controller
{
    public function __construct(private readonly CheckpointTracker $tracker) {}

    public function show(string $token): View
    {
        $history = $this->tracker->findByToken($token);

        return view('public.tracking.show', [
            'checkpoints' => $this->tracker->publicPayload($history),
        ]);
    }
}
```

- [ ] **Step 4: Create the view**

```blade
<x-app-layout>
    <div class="max-w-3xl mx-auto py-10 px-4">
        <h1 class="text-2xl font-semibold mb-6">Shipment tracking</h1>

        @if (empty($checkpoints))
            <p class="text-gray-600">No tracking information was found for this link.</p>
        @else
            <ol class="space-y-4">
                @foreach ($checkpoints as $checkpoint)
                    <li class="border rounded p-4">
                        <div class="font-medium">{{ $checkpoint['status'] }}</div>
                        @if ($checkpoint['location'])
                            <div class="text-sm text-gray-600">{{ $checkpoint['location'] }}</div>
                        @endif
                        @if ($checkpoint['notes'])
                            <div class="text-sm text-gray-500 mt-1">{{ $checkpoint['notes'] }}</div>
                        @endif
                        @if ($checkpoint['has_photo'])
                            <div class="text-xs text-gray-400 mt-1">Photo attached</div>
                        @endif
                        <div class="text-xs text-gray-400 mt-1">{{ $checkpoint['recorded_at']->format('d M Y, H:i') }}</div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-app-layout>
```

Check `resources/views` for the actual layout component name in use before finalizing (verify `<x-app-layout>` exists; if the public receipt view uses a different public-facing layout, use that one instead — check `resources/views/public/receipts/verify.blade.php` first).

- [ ] **Step 5: Add the route**

Modify `routes/web.php` — add near the other public verification routes (after the certificate-verify block, e.g. after line 162):

```php
// Public checkpoint tracking (gap-plan 1.5.11). Open to anyone holding the
// link; throttled like /verify and /verify/certificate above.
Route::get('/track/{token}', [CheckpointTrackingController::class, 'show'])
    ->middleware('throttle:checkpoint-track')->name('checkpoints.track');
```

Add the `use App\Http\Controllers\Public\CheckpointTrackingController;` import at the top with the other Public controller imports.

- [ ] **Step 6: Run the test**

Expected: PASS.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Public/CheckpointTrackingController.php resources/views/public/tracking/show.blade.php routes/web.php app/Providers/AppServiceProvider.php tests/Feature/CheckpointUpdateTest.php
git commit -m "feat: add public /track/{token} checkpoint tracking page"
```

## Task 5: Self-review pass, Pint, full suite

- [ ] **Step 1:** Run `vendor/bin/pint --dirty` and fix anything it reformats; re-add and amend into the last commit if it touches already-committed files, or add a small `style: pint --dirty` commit.
- [ ] **Step 2:** Acquire the Test-Run Lock (see below), run the full test suite, confirm the pass count is `919 + <new tests added>` with zero failures, release the lock.
- [ ] **Step 3:** Acquire the GAP_PLAN.md Lock, re-read the file fresh, update only the 1.5.11 row to mark it done and note explicitly: "Shipment integration (HasCheckpointUpdates on App\Models\Shipment) is a tracked follow-up once 1.5.10 lands — not done here." Commit, release the lock.

---

## Shared Worktree Coordination

**TEST-RUN LOCK** (required before any `artisan migrate`, `artisan test`, or `tinker` command):

```
mkdir C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
```

On success (`mkdir` succeeds — it's atomic): write `echo "checkpoint-tracking-agent" > C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock\holder.txt`, run exactly **one** test/migrate command, then immediately `rm -rf C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock`.

On failure (already exists): wait ~10s, retry, up to ~150 attempts. If `holder.txt`'s mtime is older than 15 minutes, treat the lock as stale and remove it before retrying.

Before the very first migrate/test run of this plan, confirm the testing DB resolves correctly:

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"
```
Expected output: `cameroontimberhub_testing`.

**GAP_PLAN.md LOCK**: same `mkdir`/retry mechanism, applied to editing `docs/GAP_PLAN.md` in Task 5 Step 3 only.

---

## Self-Review

**1. Spec coverage:**
- Polymorphic, not Shipment-specific — Task 1 (`trackable_type`/`trackable_id`), Scope Decision. ✓
- `HasCheckpointUpdates` trait for future consumers — Task 2. ✓
- High-entropy `tracking_token`, separate from DB id — Task 1 migration + model. ✓
- `status` enum (dispatched/in_transit/delayed/delivered) — Task 1, `CheckpointStatus`. ✓
- `location` free text — Task 1 migration. ✓
- `latitude`/`longitude` nullable decimals — Task 1 migration. ✓
- `photo_path` nullable — Task 1 migration; reuses `documents` disk convention (Scope Decision). ✓
- `notes`, `recorded_by` nullable FK to users, timestamps — Task 1 migration. ✓
- Public `/track/{token}` route + controller + view — Task 4. ✓
- Testable via test-only fixture without a real Shipment — Task 2 (in-file trait-consuming class) + Task 1/3/4 (Company as trackable). ✓
- Throttling/disclosure discipline mirroring ReceiptVerifier — Task 3 (allow-list payload), Task 4 (rate limiter, generic-page-not-token-in-URL). ✓
- Shipment wiring explicitly deferred, noted in GAP_PLAN.md — Scope Decision + Task 5 Step 3. ✓
- Module boundary respected: no edits to Product/Company/RfqType/Rfq/Category/Certificate/ContactMessage/Document/ChainedActivity/Consent/Inventory/BadgeType/BadgeService/Vehicle/Driver/Shipment/Portfolio files — verified: only new files plus `routes/web.php` and `app/Providers/AppServiceProvider.php` edits (both allowed, shared infrastructure files, additive only). ✓

**2. Placeholder scan:** No TBD/TODO markers; every step has complete code. ✓

**3. Type consistency:** `CheckpointUpdate::forTrackable`/`forToken` scopes match usage in Task 3/4. `HasCheckpointUpdates::checkpointUpdates()`/`latestCheckpoint()` match Task 2 test usage. `CheckpointTracker::findByToken()`/`publicPayload()` signatures match Task 3 and Task 4 controller usage exactly. ✓
