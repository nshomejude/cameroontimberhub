# Entitlement Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build gap-plan item 0.9c, "Entitlement enforcement" — make the platform actually respect the plan/feature data that `docs/PRICING_SPEC.md` and the existing `Plan`/`Subscription`/`Company::hasFeature()` system describe, instead of storing it as inert data. No payment gateway (product owner decided: manual/admin-activated, matching the existing `SubscriptionService::assign()` flow exactly).

**Architecture:** No new tables. `Company::hasFeature(string $key): bool` already exists and correctly reads the active plan's `features` JSON — it is simply never called anywhere except one read-only admin table badge. This plan wires it into the three places entitlements are supposed to affect real behaviour: gallery image limits, RFQ lead routing, and (informational only) the admin "featured" toggle. It also adds a `Company::maxGalleryImages(): int` numeric accessor (the boolean `hasFeature()` isn't the right shape for a count limit) and enforces it at the model layer (`CompanyGallery::creating()`), not just the Filament form layer, so every current and future creation path (API, import, form) is covered by one guard.

**Tech Stack:** Laravel 13, Filament 5, Pest.

Reference: `docs/PRICING_SPEC.md` §2 ("Subscription status, verification status... are separate concepts"), §4/§5 feature tables, `app/Models/Company.php` (`hasFeature()`, `planFeatures()`), `app/Services/SubscriptionService.php`, `app/Services/RfqTriageService.php`, `app/Models/CompanyGallery.php`, `app/Filament/Exporter/Resources/Companies/Schemas/CompanyForm.php`, `database/seeders/PlanSeeder.php` (the real feature keys: `max_gallery`, `verified_badge`, `leads_receive`, `featured`, `api`).

## Scope decision (read before executing)

**What already exists, confirmed by investigation:** `Company::hasFeature(string $key): bool` (`app/Models/Company.php`) correctly reads `$this->plan?->features`, defaulting to `false` for a company with no assigned plan. `SubscriptionService::assign()`/`cancel()` (admin-only, activity-logged) already implement the exact "manual/admin-activated" billing model the product owner just confirmed — **no new billing/checkout work is needed**, only enforcement of the entitlements that assignment already grants. The only current consumer of `hasFeature()` is a read-only badge in `app/Filament/Resources/Companies/Tables/CompaniesTable.php` — a `grep -rln hasFeature app` before this plan finds exactly two files, and one is `Company.php` itself.

**`verified_badge` is explicitly NOT enforced by this plan.** `docs/PRICING_SPEC.md` §2 states as a hard principle: "Subscription status, verification status, certification status and trust/ranking status are separate concepts. Paying for a plan does not automatically create a verification badge." The `verified_badge` plan feature key therefore describes *marketing eligibility copy* (what a plan's sales page can claim a customer becomes eligible to pursue), not a switch this plan should wire into `VerificationFlowService`/`Verification`. Wiring it would directly contradict the spec's own stated principle. No task below touches verification.

**`featured` is enforced as an admin-visible signal, not a hard block.** `is_featured` on `Company` is a plain boolean an admin can already set for curatorial reasons (e.g. a manually-selected homepage spotlight) independent of self-service plan purchase — `docs/PRICING_SPEC.md` itself says "Promotional placement must never override safety, compliance, verification or ranking rules," implying admin discretion is expected to remain. Task 3 adds a visible warning in the admin table/form when `is_featured = true` on a company whose plan does *not* include the `featured` feature, so staff can see and correct an inconsistency — it does not silently unfeature a company or block the toggle, which would remove legitimate admin discretion this plan has no evidence is unwanted.

**`api` is not enforced in this plan.** Investigated: no company-scoped, authenticated business API surface exists today to gate (the existing `api/v1` routes are public/buyer-facing, not company-authenticated). Gating a surface that doesn't exist would be fabricating enforcement against nothing. Left as recorded-but-unenforceable plan data, exactly as honest as leaving it alone — noted as a tracked follow-up (0.9d) rather than silently ignored.

**What this plan actually enforces, and why these two:** `max_gallery` (Task 1–2) and `leads_receive` (Task 4) are the two `Plan::features` keys with a real, unambiguous, already-built consumer to gate — a real `CompanyGallery` creation path and a real `RfqTriageService::route()` call that currently ignores plan tier entirely. Both are additive, backward-compatible guards; no existing passing test should need to change except where a test's fixture data happens to cross a limit it wasn't designed to respect (Task 2/4's steps say to check for this).

---

### Task 1: `Company::maxGalleryImages()` numeric accessor

**Files:**
- Modify: `app/Models/Company.php`
- Test: `tests/Feature/CompanyEntitlementTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Company;
use App\Models\Plan;

it('reads the numeric max_gallery limit from the active plan', function () {
    $plan = Plan::factory()->create(['features' => ['max_gallery' => 10]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);

    expect($company->maxGalleryImages())->toBe(10);
});

it('defaults to 3 gallery images when the company has no plan at all', function () {
    $company = Company::factory()->create(['plan_id' => null]);

    expect($company->maxGalleryImages())->toBe(3);
});

it('defaults to 3 when the assigned plan has no max_gallery key set', function () {
    $plan = Plan::factory()->create(['features' => ['verified_badge' => true]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);

    expect($company->maxGalleryImages())->toBe(3);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CompanyEntitlementTest.php`
Expected: FAIL — `Call to undefined method App\Models\Company::maxGalleryImages()`.

- [ ] **Step 3: Add the accessor**

In `app/Models/Company.php`, immediately after the existing `hasFeature()` method:

```php
    /**
     * The numeric gallery-image cap for this company's active plan
     * (docs/PRICING_SPEC.md §5's `max_gallery` feature). hasFeature() is
     * boolean-shaped and wrong for a count -- this reads the same
     * planFeatures() data but as an int, defaulting to the Free plan's
     * documented limit (3) for a company with no plan or a plan missing
     * the key entirely, never 0 or unlimited by omission.
     */
    public function maxGalleryImages(): int
    {
        return (int) data_get($this->planFeatures(), 'max_gallery', 3);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CompanyEntitlementTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Models/Company.php tests/Feature/CompanyEntitlementTest.php
git commit -m "Add Company::maxGalleryImages(), a numeric accessor for the max_gallery entitlement"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that's corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force` only after confirming `--env=testing` genuinely targets `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"`. Never run `migrate:fresh` without that verification — it has silently wiped the dev database before in this project.

---

### Task 2: Enforce the gallery limit at the model layer

**Files:**
- Modify: `app/Models/CompanyGallery.php`
- Test: `tests/Feature/CompanyGalleryLimitTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Company;
use App\Models\CompanyGallery;
use App\Models\Plan;
use Illuminate\Database\QueryException;

it('allows creating gallery images up to the plan limit', function () {
    $plan = Plan::factory()->create(['features' => ['max_gallery' => 2]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);

    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'a.jpg']);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'b.jpg']);

    expect($company->gallery()->count())->toBe(2);
});

it('refuses to create a gallery image beyond the plan limit', function () {
    $plan = Plan::factory()->create(['features' => ['max_gallery' => 1]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'a.jpg']);

    expect(fn () => CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'b.jpg']))
        ->toThrow(RuntimeException::class, 'gallery image limit');

    expect($company->gallery()->count())->toBe(1);
});

it('uses the default 3-image limit for a company with no plan', function () {
    $company = Company::factory()->create(['plan_id' => null]);

    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'a.jpg']);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'b.jpg']);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'c.jpg']);

    expect(fn () => CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'd.jpg']))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CompanyGalleryLimitTest.php`
Expected: FAIL — the fourth image is created without error.

- [ ] **Step 3: Verify against reality first**

Before writing the guard, run `grep -rn "CompanyGallery::" app database/seeders database/factories tests` to check for any existing seeder or factory that creates more than 3 gallery rows for a Free/no-plan company — if one exists, note it in your final report; it will start failing once this guard lands and its fixture needs a plan bump, not a guard exception.

- [ ] **Step 4: Add the guard**

In `app/Models/CompanyGallery.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class CompanyGallery extends Model
{
    use HasFactory;

    protected $table = 'company_gallery';

    protected $guarded = ['id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyGallery $image) {
            $company = $image->company_id ? Company::find($image->company_id) : null;

            if (! $company) {
                return;
            }

            $limit = $company->maxGalleryImages();

            if ($company->gallery()->count() >= $limit) {
                throw new RuntimeException("This company's plan allows a gallery image limit of {$limit} images. Upgrade the plan or remove an existing image before adding another.");
            }
        });
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CompanyGalleryLimitTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full suite to check for the fixture collision found in Step 3**

Run: `php artisan test`
Expected: green. If a pre-existing test/factory now fails because it created more gallery images than the acting company's plan allows, fix that fixture (assign a plan with a high enough `max_gallery`, or reduce the fixture's image count) — do not weaken the guard to accommodate it.

- [ ] **Step 7: Wire the same limit into the Filament form (UX, not the security boundary)**

In `app/Filament/Exporter/Resources/Companies/Schemas/CompanyForm.php`, change the gallery repeater (around the `Repeater::make('gallery')` block) to cap `maxItems` dynamically:

```php
                        Repeater::make('gallery')
                            ->relationship()
                            ->columns(2)
                            ->maxItems(fn (?Company $record) => $record?->maxGalleryImages() ?? 3)
                            ->helperText(fn (?Company $record) => 'Your plan allows up to '.($record?->maxGalleryImages() ?? 3).' gallery images.')
                            ->schema([
                                FileUpload::make('image_path')->image()->disk('public')->directory('companies/gallery')->required(),
                                TextInput::make('caption'),
                            ])
                            ->addActionLabel('Add image')
                            ->columnSpanFull(),
```

Confirm `Company` is imported at the top of the file (`use App\Models\Company;`) — add the import if it is not already present.

- [ ] **Step 8: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Models/CompanyGallery.php app/Filament/Exporter/Resources/Companies/Schemas/CompanyForm.php tests/Feature/CompanyGalleryLimitTest.php
git commit -m "Enforce the max_gallery plan entitlement at the model layer, mirrored in the exporter form UI"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Admin-visible warning when a company is featured outside its plan

**Files:**
- Modify: `app/Filament/Resources/Companies/Tables/CompaniesTable.php`
- Test: `tests/Feature/FeaturedEntitlementWarningTest.php` (new)

- [ ] **Step 1: Read the existing table column first**

Read `app/Filament/Resources/Companies/Tables/CompaniesTable.php` in full — find the existing `hasFeature()` badge column (the one confirmed consumer from this plan's investigation) and the `is_featured` column if one already exists, so this task extends the real current structure rather than guessing its shape.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Filament\Tables\Testing\TestsTables;
use function Pest\Livewire\livewire;

it('shows a mismatch warning for a featured company whose plan lacks the featured entitlement', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plan = Plan::factory()->create(['features' => ['featured' => false]]);
    $company = Company::factory()->create(['plan_id' => $plan->id, 'is_featured' => true, 'legal_name' => 'Mismatch Co']);

    livewire(CompanyResource\Pages\ListCompanies::class)
        ->actingAs($admin)
        ->assertSee('Mismatch Co')
        ->assertSee('Featured without plan');
});

it('shows no mismatch warning when a featured company plan includes the featured entitlement', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plan = Plan::factory()->create(['features' => ['featured' => true]]);
    Company::factory()->create(['plan_id' => $plan->id, 'is_featured' => true, 'legal_name' => 'Consistent Co']);

    livewire(CompanyResource\Pages\ListCompanies::class)
        ->actingAs($admin)
        ->assertSee('Consistent Co')
        ->assertDontSee('Featured without plan');
});
```

Adjust the Livewire component class/route to whatever `CompaniesTable.php`'s actual list page class is (confirmed by Step 1's read) if it differs from `CompanyResource\Pages\ListCompanies` — do not guess if the real class name differs.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/FeaturedEntitlementWarningTest.php`
Expected: FAIL — no such text rendered yet.

- [ ] **Step 4: Add the warning column/indicator**

Using the exact column-building convention Step 1 found for the existing `hasFeature()` badge column (match its style — likely a `Tables\Columns\IconColumn` or `TextColumn` with a `formatStateUsing`/`badge` pattern), add a new column immediately after the `is_featured` column (or after the plan/feature column if no dedicated `is_featured` column exists yet) that renders "Featured without plan" only when `$record->is_featured && ! $record->hasFeature('featured')`, and renders nothing otherwise (do not show a "consistent" badge for the normal case — only surface the exception, so the table stays scannable).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/FeaturedEntitlementWarningTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Filament/Resources/Companies/Tables/CompaniesTable.php tests/Feature/FeaturedEntitlementWarningTest.php
git commit -m "Surface a 'featured without plan' warning in the admin companies table"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 4: Enforce `leads_receive` in RFQ routing

**Files:**
- Modify: `app/Services/RfqTriageService.php`
- Test: `tests/Feature/RfqTest.php` (extend the existing file — do not create a new one)

- [ ] **Step 1: Read the existing file first**

Read `app/Services/RfqTriageService.php`'s `route()` method in full (already partially shown during planning — confirm it matches: takes `Rfq $rfq, array $companyIds, User $actor, LeadFlowService $leads`, loops `$companyIds`, creates an `RfqCompany` routing per id). Read the existing consent-guard tests in `tests/Feature/RfqTest.php` to match their fixture conventions exactly (`makeRfq()` helper, `Company::factory()->publiclyVisible()->create()`, admin actor via `assignRole('admin')`) — this task's new tests must use the same helpers, not invent new ones.

- [ ] **Step 2: Write the failing test**

Append to `tests/Feature/RfqTest.php` (match the file's existing `use` statements and helper functions — do not redeclare `makeRfq()` if it already exists):

```php
it('does not route an approved RFQ to a company whose plan lacks the leads_receive entitlement', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plan = \App\Models\Plan::factory()->create(['features' => ['leads_receive' => false]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);
    $rfq = makeRfq(); // reuse this file's existing helper -- adjust the call if the real helper signature differs

    $routed = app(\App\Services\RfqTriageService::class)->route($rfq, [$company->id], $admin, app(\App\Services\LeadFlowService::class));

    expect($routed)->toBe(0)
        ->and(\App\Models\RfqCompany::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeFalse();
});

it('routes an approved RFQ to a company whose plan includes leads_receive', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plan = \App\Models\Plan::factory()->create(['features' => ['leads_receive' => true]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);
    $rfq = makeRfq();

    $routed = app(\App\Services\RfqTriageService::class)->route($rfq, [$company->id], $admin, app(\App\Services\LeadFlowService::class));

    expect($routed)->toBe(1)
        ->and(\App\Models\RfqCompany::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeTrue();
});

it('still routes to a company with no plan assigned at all, matching the pre-existing default behaviour for unplanned companies', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => null]);
    $rfq = makeRfq();

    $routed = app(\App\Services\RfqTriageService::class)->route($rfq, [$company->id], $admin, app(\App\Services\LeadFlowService::class));

    // A company with genuinely no plan defaults hasFeature('leads_receive') to
    // false (Company::hasFeature's own documented default) -- so this SHOULD
    // now be 0, not 1. This test intentionally documents the real, changed
    // behaviour rather than assuming the old unconditional-routing default;
    // if it fails, read Company::hasFeature()'s actual default before
    // "fixing" this test -- the failure would mean the guard below is wrong,
    // not this test.
    expect($routed)->toBe(0);
});
```

- [ ] **Step 3: Run to verify the first two fail (and the third currently passes with `toBe(1)` before your edit — re-check its actual current value before asserting `toBe(0)`, per its own comment)**

Run: `php artisan test tests/Feature/RfqTest.php`
Expected: the `leads_receive` tests fail (routing still happens unconditionally, both `false`-plan and no-plan cases currently route successfully).

- [ ] **Step 4: Add the guard**

In `app/Services/RfqTriageService.php`'s `route()` method, inside the `foreach ($companyIds as $companyId)` loop, before the existing `RfqCompany::firstOrCreate(...)` call:

```php
        foreach ($companyIds as $companyId) {
            $company = Company::find($companyId);

            // leads_receive entitlement (docs/PRICING_SPEC.md §5) -- a
            // company whose plan does not include lead delivery is skipped
            // silently here (not an error): the caller passed a candidate
            // list, this is the entitlement filter on top of it, exactly
            // like the pre-existing consent guard above it in this method.
            if (! $company || ! $company->hasFeature('leads_receive')) {
                continue;
            }

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
```

Confirm `use App\Models\Company;` is already imported at the top of the file (it very likely is, given `Company::find` is trivial to need elsewhere in this class) — add it if missing.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/RfqTest.php`
Expected: PASS, full file. Pay particular attention to any PRE-EXISTING routing test in this file that used a `Company::factory()->publiclyVisible()->create()` with no explicit plan — per Step 2's third test, this guard changes its outcome from routing to not-routing. If a pre-existing test breaks, that is expected per this task's intent; fix that test's fixture (assign a plan with `leads_receive => true`) rather than weakening the guard — but read it carefully first in case it reveals the guard is too strict for a case this plan didn't anticipate (e.g. a company mid-admin-workflow with no plan yet that legitimately still needs to receive its first lead) and report that as a finding rather than silently patching around it.

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/RfqTriageService.php tests/Feature/RfqTest.php
git commit -m "Enforce the leads_receive plan entitlement in RFQ routing"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 5: Record the `api` entitlement gap and final verification

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Append the follow-up item**

Read the current end of `docs/GAP_PLAN.md`'s Phase 0 table first (item numbers may have shifted since this plan was written) and add a row for the honestly-deferred `api` entitlement:

> **0.9d — `api` plan entitlement has no surface to enforce yet.** `docs/PRICING_SPEC.md`'s `api` feature key (granted by the Enterprise supplier plan) has no company-scoped, authenticated business API to gate today — the existing `api/v1` routes are public/buyer-facing, not company-authenticated. Recorded honestly as unenforceable rather than gated against a surface that doesn't exist. Revisit once a company-authenticated API (e.g. Sanctum tokens issued per company) is actually built. | Blocks nothing currently sold; the Enterprise plan's `api` feature is presently informational only. | TBD — depends on a company-facing API existing first |

- [ ] **Step 2: Full suite one final time**

```bash
php artisan test
```

Expected: fully green.

- [ ] **Step 3: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Record the api entitlement's missing enforcement surface as a follow-up (0.9d)"
```

No test run needed beyond Step 2 — this task is documentation-only after that.

---

## Self-Review Notes

- **Spec coverage:** every `Plan::features` key from `database/seeders/PlanSeeder.php` is addressed: `max_gallery` (Tasks 1–2, enforced), `verified_badge` (explicitly NOT enforced, with the spec's own §2 principle cited as the reason), `leads_receive` (Task 4, enforced), `featured` (Task 3, surfaced not hard-blocked, with reasoning), `api` (Task 5, honestly recorded as unenforceable today rather than silently dropped).
- **No fabricated infrastructure:** no payment gateway, no new billing tables, no checkout flow — matches the product owner's explicit "manual/admin-activated" decision and the pre-existing `SubscriptionService` this plan builds on top of rather than replaces.
- **Type/behaviour consistency:** `Company::maxGalleryImages()` (Task 1) is the exact method `CompanyGallery::booted()` (Task 2) and the Filament form (Task 2 Step 7) call. `Company::hasFeature('leads_receive')` (already existing) is the exact method Task 4's guard calls — no new entitlement-reading method invented where the existing one already fits.
