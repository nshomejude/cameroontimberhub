# Verification Consumer Migration Implementation Plan v2 (0.2b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `Company`'s compliance-review workflow a read-only presence in the polymorphic `Verification` framework (item 0.2), so cross-entity reporting ("everything currently mid-verification") can eventually include companies — **without** touching `companies.status`, `verification_requests`, `CompanyStatusService`, `VerificationService`, or `BadgeService`, which stay the sole source of truth for every real state transition and every badge-issuance decision, unchanged.

**Architecture:** This plan replaces `docs/superpowers/plans/2026-08-28-verification-consumer-migration.md`, which a dispatched agent correctly blocked on (see `docs/GAP_PLAN.md` row `0.2b`) before writing any code. That agent found badge issuance is not a single status transition but a per-badge-type, document-driven evaluation loop (`BadgeService::issue()`) fired from two independent call sites. This investigation goes one step further and finds a second, harder blocker the original plan never reached: **`VerificationStage` (item 0.2's enum) has no case for `suspended` or `archived`, but `companies.status` genuinely needs both** (`CompanyStatusService::TRANSITIONS` supports `verified→suspended→verified` reactivation and `*→archived`), and **`verification_requests`' real workflow is flat** (`pending → in_review → approved|rejected` — one review step) while `VerificationStage`'s 9 cases encode five distinct sequential sub-stages (`company_info`, `business_docs`, `identity_kyc`, `forestry_legal_docs`, `compliance_review`) that have no corresponding real checkpoints for `Company` today. A stage-by-stage cutover would either corrupt the shared enum for `Product` (item 0.2's only real consumer today) or fabricate four checkpoint records for reviews that never happened. The corrected architecture is therefore a **read-only mirror, not a cutover**: `Company` gets `HasVerification` and a `Verification` row that a new `CompanyVerificationMirror` service keeps in sync (best-effort, failure-isolated) whenever the *real* systems (`CompanyStatusService`, `VerificationService`) transition — badge issuance (`BadgeService`, `CompanyVerified`, `BadgeIssued`) is not touched at all, because it cannot be represented as a stage transition even in principle (it is N independent yes/no document checks, not one state).

**Tech Stack:** Laravel 13, Filament 5, Pest, PostgreSQL.

---

## Scope Decision (read before executing)

### What the previous plan got wrong, confirmed by re-reading every file it should have read

1. **`ApproveVerificationRequest` → `VerificationService::approve()` → `BadgeService::issue()` is a per-badge-type loop, not a status transition** (`app/Actions/Verification/ApproveVerificationRequest.php:16-31`, `app/Services/VerificationService.php:108-149`). `VerificationService::approve()` iterates `$request->requested_badges` (a JSON array of `BadgeType` values chosen when the request was submitted, `app/Services/VerificationService.php:37-41`) and calls `BadgeService::issue()` once per value. `BadgeService::issue()` (`app/Services/BadgeService.php:53-87`) only calls `canIssue()` (`app/Services/BadgeService.php:29-51`), which checks — per badge type, from `config('compliance.badge_requirements')` (`config/compliance.php:11-20`) — whether every backing `document_type` has an **approved, unexpired `CompanyDocument`**. It never reads `companies.status` or `verification_requests.status` at all. A company can have `verified_company` issued and `verified_exporter` skipped in the same `approve()` call, correctly reported back as `{issued: [...], skipped: [...]}` (`VerificationService.php:147`). There is no single "verified" moment to hang a linear stage transition off — there are up to 8 independent yes/no decisions (`BadgeType` has 8 cases: `app/Enums/BadgeType.php:7-14`), one of which (`premium_member`) is plan-gated and can never be issued this way at all (`BadgeService.php:31-33`).

2. **Two independent dispatch sites for `CompanyVerified`, with different side effects.**
   - `app/Actions/Verification/ApproveVerificationRequest.php:22-28` — dispatches `CompanyVerified` **and** `BadgeIssued` for every currently-active badge, but only when `$result['issued']` is non-empty (i.e., at least one badge was actually issued this call).
   - `app/Actions/Company/VerifyCompany.php:14-21` — a **separate, simpler action** used when staff verify a company directly (not through a `VerificationRequest`). It calls `CompanyStatusService::approve()` and dispatches `CompanyVerified` **unconditionally**, with **no `BadgeIssued` dispatch at all** — a company verified this way gets no badges, by design (nothing here evaluates `BadgeService`).

   These are not the same event firing from two entry points into one state machine; they are two different features with different guarantees. Any migration that funnels both through one `VerificationFlowService::publish()` call must reproduce both behaviours exactly, including `VerifyCompany`'s badge-less path — the previous plan's Task 3 never mentions `VerifyCompany.php` at all, which is a real gap, not an oversight the original author flagged.

3. **`companies.status` is a real 6-value machine with branches `VerificationStage` cannot express** (`app/Enums/CompanyStatus.php:7-14`, `app/Services/CompanyStatusService.php:19-26`):
   ```
   draft     -> pending
   pending   -> verified, rejected, draft
   verified  -> suspended, pending, archived
   suspended -> verified, archived
   rejected  -> pending, archived
   archived  -> (terminal)
   ```
   `VerificationStage` (`app/Enums/VerificationStage.php:15-23`) has exactly 9 cases: `registered, company_info, business_docs, identity_kyc, forestry_legal_docs, compliance_review, verified, rejected, published` — **no `suspended`, no `archived`**. `VerificationFlowService::FORWARD` (`app/Services/VerificationFlowService.php:24-31`) is a single linear chain with one target per source stage, and `assertNotTerminal()` (`VerificationFlowService.php:165-169`) throws on any transition attempt once a `Verification` reaches `Rejected` or `Published` — there is no supported way to move a `Verification` back out of a terminal stage, which `suspended→verified` reactivation and `rejected→pending` (re-submission after rejection) both require for `Company`.

4. **`verified` does not mean `published` for `Company` the way it does for the generic framework's terminal stage.** `Company::scopePubliclyVisible()` (`app/Models/Company.php:273-282`) requires `status = verified` **and** `logo_path`, `description`, `region` all present **and** related `species`/`contacts` to exist. A company can sit at `status = verified` indefinitely without ever being "published" in the directory sense `VerificationStage::Published` implies for `Product`. Mapping `verified → verified → published` (as the blocked plan's Scope Decision hypothesized) silently asserts every verified company is publicly listed, which is false today.

5. **Real consumer count.** Re-running the previous plan's own inventory command against the current tree:
   ```
   grep -rln "companies\.status\|verification_requests\|CompanyStatusService\|CompanyStatus::" app resources/views database --include=*.php --include=*.blade.php | grep -v /Migrations/
   ```
   returns 25 files today (`app/Actions/Auth/RegisterAccount.php`, `app/Actions/Company/{ArchiveCompany,SubmitCompanyForReview,SuspendCompany,VerifyCompany}.php`, `app/Filament/Exporter/Resources/Companies/Pages/EditCompany.php`, `app/Filament/Resources/Companies/Tables/CompaniesTable.php`, `app/Http/Controllers/Public/InquiryController.php`, `app/Models/Company.php`, `app/Models/RfqCompany.php`, `app/Services/{CompanyStatusService,LeadFlowService,QuoteService,ReceiptVerifier,VerificationFlowService,VerificationService}.php`, `database/factories/CompanyFactory.php`, `database/seeders/DemoCompanySeeder.php`, 6 Blade views, plus 3 migration files the grep can't exclude by name pattern alone). The 27 figure in the original plan is close but not exact — re-run this grep at execution time rather than trusting either number.

### The architecture decision this plan makes

**Reject:** extending `VerificationStage` with `Suspended`/`Archived` cases, or collapsing `Company`'s flat review into the 5-stage sequence. Both corrupt the *shared, generic* framework for the sake of one consumer whose real workflow shape doesn't fit it — exactly the kind of silent redefinition item 0.2's own docblock warns against (`app/Models/Verification.php:14-16`: "Product today; carbon projects, vehicles, artisans as those land" — a generic sequence meant to be reused as-is, not bent per-consumer).

**Adopt:** `Company` gets `HasVerification` and a `Verification` row used **only** for read/reporting purposes (a future "everything mid-verification" dashboard across `Product` + `Company` + whatever comes next). A new `CompanyVerificationMirror` service is called from the four real mutation points (`VerificationService::submit/startReview/approve/reject`, plus `VerifyCompany`) **after** the real transition has already succeeded, and is wrapped so a mirror failure is logged and swallowed, never thrown — the mirror is derived state, not a dependency of the real workflow. Because `Company`'s real review has no distinct sub-stages, the mirror does not walk `VerificationFlowService::FORWARD` one hop at a time (that would either get stuck after one `approve()` call or fabricate checkpoints for stages nobody reviewed). It uses one new, additive method, `VerificationFlowService::fastForward()`, that jumps straight to a target stage in a single explicitly-labelled checkpoint. `suspended`/`archived`/re-submission-after-rejection have **no** mirror representation — the mirror simply does nothing on those transitions (documented, not silently dropped) until a future decision extends the framework or accepts a coarser mapping; this is safer than fabricating semantics nobody signed off on.

**Badge issuance is explicitly out of scope for this plan.** `BadgeService`, `VerificationService::approve()`'s badge loop, both `CompanyVerified`/`BadgeIssued` dispatch sites, and `config('compliance.badge_requirements')` are not modified anywhere in this plan. This is not a deferral of hard work — it's the corrected finding that badge issuance cannot be a stage-transition side effect in the first place, so there is nothing to "re-wire."

**What "migrated" means for the ~25 consumers, given this:**
- **Read paths** (Blade views, `CompaniesTable`, `EditCompany`, `LeadFlowService`, `QuoteService`, `ReceiptVerifier`, `RfqCompany`) — **unchanged**. They read `companies.status`/badges directly today and continue to; there is no new data for them to read that would improve on what they already have, and `Verification::stage` is not authoritative for `Company` in this plan.
- **The 4 real mutation points** (`VerificationService::submit/startReview/approve/reject`, `VerifyCompany::execute`) — each gets one addition: a call into `CompanyVerificationMirror` after its existing logic, per Task 2.
- **`CompanyStatusService`, `ArchiveCompany`, `SuspendCompany`, `SubmitCompanyForReview`** — unchanged. `SubmitCompanyForReview` and `VerificationService::submit()` both call `CompanyStatusService::submit()` already (confirm at Task 1 Step 1); the mirror hooks into `VerificationService::submit()` only, since that's the one path that also opens a `VerificationRequest`.
- **`RegisterAccount`, `CompanyFactory`, `DemoCompanySeeder`** — unchanged; they create companies at `status = draft`, which needs no mirror row until the company is actually submitted for review.

---

### Task 1: Confirm real values and current call graph before writing code

**Files:** none modified — this task is verification-only, its output feeds Task 2's mapping table.

- [ ] **Step 1: Run the confirmation greps**

```bash
cd "C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter"
grep -n "companies_status_check" database/migrations/*.php
grep -n "verification_requests.*_check" database/migrations/*.php
grep -n "TRANSITIONS" -A 10 app/Services/CompanyStatusService.php
grep -rn "CompanyStatusService::submit\|companyStatus->submit" app
grep -rn "VerificationService::submit\|->submit(" app/Actions/Company/SubmitCompanyForReview.php
```

Expected output confirms: `companies_status_check` lists exactly `draft,pending,verified,suspended,rejected,archived`; `verification_requests` status check lists exactly `pending,in_review,approved,rejected`; `SubmitCompanyForReview` and `VerificationService::submit()` both ultimately call `CompanyStatusService::submit()` (already read in full above — `VerificationService.php:43-45` calls it directly when the company is `Draft`/`Rejected`).

- [ ] **Step 2: Read `app/Actions/Company/SubmitCompanyForReview.php`, `SuspendCompany.php`, `ArchiveCompany.php` in full**, confirming none of them independently dispatch `CompanyVerified`/`BadgeIssued` (only `VerifyCompany.php` and `ApproveVerificationRequest.php` do, per the Scope Decision above — verify this stays true, do not assume).

```bash
grep -n "CompanyVerified\|BadgeIssued" app/Actions/Company/*.php app/Actions/Verification/*.php
```

Expected: only `VerifyCompany.php` and `ApproveVerificationRequest.php` appear.

- [ ] **Step 3: Report findings** (as plain output, not a commit — this task produces no diff) before proceeding to Task 2. If any of the above expectations don't hold, stop and re-derive the Scope Decision rather than continuing on a stale assumption.

---

### Task 2: Add `HasVerification` to `Company` and the `fastForward` mirror primitive

**Files:**
- Modify: `app/Models/Company.php` (add `use HasVerification;`)
- Modify: `app/Services/VerificationFlowService.php` (add `fastForward()`)
- Test: `tests/Feature/VerificationFlowServiceFastForwardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\VerificationFlowService;

it('jumps a verification directly to a target stage in one labelled checkpoint', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $flow = app(VerificationFlowService::class);

    $verification = $flow->open($company);

    $result = $flow->fastForward($verification, VerificationStage::Verified, $actor, 'Mirrored from verification_requests approval');

    expect($result->stage)->toBe(VerificationStage::Verified);
    expect($result->checkpoints)->toHaveCount(1);
    expect($result->checkpoints->first()->status)->toBe(CheckpointStatus::Approved);
    expect($result->checkpoints->first()->notes)->toBe('Mirrored from verification_requests approval');
});

it('refuses to fast forward a terminal verification', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $flow = app(VerificationFlowService::class);

    $verification = $flow->open($company);
    $flow->fastForward($verification, VerificationStage::Published, $actor, 'terminal');

    expect(fn () => $flow->fastForward($verification->fresh(), VerificationStage::Verified, $actor, 'should fail'))
        ->toThrow(RuntimeException::class);
});

it('does not disturb Products own step-by-step FORWARD flow', function () {
    $product = Product::factory()->create();
    $actor = User::factory()->create();
    $flow = app(VerificationFlowService::class);

    $verification = $flow->open($product);
    $flow->approve($verification, $actor);

    expect($verification->fresh()->stage)->toBe(VerificationStage::CompanyInfo);
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
php artisan test tests/Feature/VerificationFlowServiceFastForwardTest.php
```
Expected: FAIL — `Call to undefined method App\Services\VerificationFlowService::fastForward()`.

- [ ] **Step 3: Add `fastForward()` to `VerificationFlowService`**

```php
    /**
     * Jump directly to a target stage in ONE checkpoint, for consumers whose
     * real review process has no distinct per-stage checkpoints of its own
     * (see docs/superpowers/plans/2026-08-29-verification-consumer-migration-v2.md
     * — Company's flat pending/in_review/approved/rejected workflow). Unlike
     * approve(), this does not require $target to be FORWARD's single next
     * hop; it still refuses a terminal source stage.
     */
    public function fastForward(Verification $verification, VerificationStage $target, User $actor, string $note): Verification
    {
        $this->assertNotTerminal($verification);

        return DB::transaction(function () use ($verification, $target, $actor, $note) {
            $verification->checkpoints()->create([
                'stage' => $verification->stage,
                'status' => $target === VerificationStage::Rejected ? CheckpointStatus::Rejected : CheckpointStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'notes' => $note,
            ]);

            $verification->update([
                'stage' => $target,
                'published_at' => $target === VerificationStage::Published ? now() : $verification->published_at,
            ]);

            return $verification->fresh();
        });
    }
```

Add this method to `app/Services/VerificationFlowService.php`, placed after `publish()` (before `protected function assertNotTerminal`). No other method in the file changes.

- [ ] **Step 4: Add `HasVerification` to `Company`**

Read `app/Models/Company.php` in full first and confirm (per the pattern that recurred with `Species`/`Product` in items 0.1/0.2) there is no existing `verification()` method or `status`-only stopgap that would collide — `Company::status` is a plain `CompanyStatus` cast column, not a method, so no collision is expected, but verify before editing:

```bash
grep -n "function verification\|function isVerified" app/Models/Company.php
```
Expected: no matches.

Add the trait import and use statement:

```php
use App\Models\Concerns\HasVerification;
```

and inside the `Company` class body, alongside its other `use` trait statements:

```php
    use HasVerification;
```

- [ ] **Step 5: Run tests, Pint, commit**

```bash
php artisan test tests/Feature/VerificationFlowServiceFastForwardTest.php
vendor/bin/pint --dirty
git add app/Models/Company.php app/Services/VerificationFlowService.php tests/Feature/VerificationFlowServiceFastForwardTest.php
git commit -m "Add HasVerification to Company and a fastForward primitive for flat (non-staged) consumers"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this.

---

### Task 3: `CompanyVerificationMirror` — the failure-isolated write-through

**Files:**
- Create: `app/Services/CompanyVerificationMirror.php`
- Test: `tests/Feature/CompanyVerificationMirrorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\User;
use App\Models\Verification;
use App\Services\CompanyVerificationMirror;

it('opens a Verification row when a company is submitted for review', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();

    app(CompanyVerificationMirror::class)->submitted($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification)->not->toBeNull();
    expect($verification->stage)->toBe(VerificationStage::Registered);
});

it('fast forwards the mirror to verified on approval, without touching badges', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $mirror = app(CompanyVerificationMirror::class);

    $mirror->submitted($company, $actor);
    $mirror->approved($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Verified);
});

it('fast forwards the mirror to rejected on rejection', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $mirror = app(CompanyVerificationMirror::class);

    $mirror->submitted($company, $actor);
    $mirror->rejected($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Rejected);
});

it('never throws even if no open mirror row exists yet', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();

    // approved() called without a prior submitted() — e.g. VerifyCompany's
    // direct-verify path, which never opens a VerificationRequest.
    app(CompanyVerificationMirror::class)->approved($company, $actor);

    expect(Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
php artisan test tests/Feature/CompanyVerificationMirrorTest.php
```
Expected: FAIL — class `App\Services\CompanyVerificationMirror` not found.

- [ ] **Step 3: Write `CompanyVerificationMirror`**

```php
<?php

namespace App\Services;

use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A READ-ONLY mirror of Company's real compliance-review state
 * (companies.status + verification_requests, both unchanged and still
 * authoritative — see docs/superpowers/plans/2026-08-29-verification-consumer-migration-v2.md)
 * into the polymorphic Verification framework from item 0.2, for future
 * cross-entity reporting only. Every method here is called AFTER the real
 * transition has already succeeded, and every method swallows its own
 * failures — a broken mirror must never block or reverse a real company
 * status change or a real badge issuance.
 *
 * Deliberately has no suspended()/archived()/resubmitted() methods:
 * VerificationStage has no equivalent stages for those (see this plan's
 * Scope Decision) so there is nothing correct to mirror them to yet.
 */
class CompanyVerificationMirror
{
    public function __construct(private readonly VerificationFlowService $flow) {}

    public function submitted(Company $company, User $actor): void
    {
        $this->safely(function () use ($company) {
            $this->flow->open($company);
        });
    }

    public function approved(Company $company, User $actor): void
    {
        $this->safely(function () use ($company, $actor) {
            $verification = $this->flow->open($company);

            if ($verification->stage === VerificationStage::Verified) {
                return;
            }

            $this->flow->fastForward($verification, VerificationStage::Verified, $actor, 'Mirrored: company verification approved (verification_requests / VerifyCompany)');
        });
    }

    public function rejected(Company $company, User $actor): void
    {
        $this->safely(function () use ($company, $actor) {
            $verification = $this->flow->open($company);

            $this->flow->fastForward($verification, VerificationStage::Rejected, $actor, 'Mirrored: company verification rejected');
        });
    }

    protected function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::warning('CompanyVerificationMirror write failed; real Company verification state is unaffected.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run tests, Pint, commit**

```bash
php artisan test tests/Feature/CompanyVerificationMirrorTest.php
vendor/bin/pint --dirty
git add app/Services/CompanyVerificationMirror.php tests/Feature/CompanyVerificationMirrorTest.php
git commit -m "Add CompanyVerificationMirror: failure-isolated read-only mirror of Company verification state"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 2.

---

### Task 4: Call the mirror from the 4 real mutation points

**Files:**
- Modify: `app/Services/VerificationService.php:30-51` (`submit`), `app/Services/VerificationService.php:109-149` (`approve`), `app/Services/VerificationService.php:151-170` (`reject`)
- Modify: `app/Actions/Company/VerifyCompany.php`
- Test: `tests/Feature/CompanyVerificationMirrorIntegrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\VerificationStage;
use App\Actions\Company\VerifyCompany;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\Verification;
use App\Services\VerificationService;

it('mirrors a real verification_requests approval into a Verification row, badges untouched', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Draft]);
    $actor = User::factory()->create();
    $service = app(VerificationService::class);

    $request = $service->submit($company);
    $service->startReview($request, $actor);
    $result = $service->approve($request->fresh(), $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Verified);
    // badge issuance result is exactly what BadgeService/VerificationService already decided —
    // this test does not assert on $result['issued'] because no documents were approved in this
    // fixture, proving the mirror addition changes nothing about that decision.
    expect($result['issued'])->toBe([]);
});

it('mirrors VerifyCompanys direct-verify path too, which issues no badges', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Pending]);
    $actor = User::factory()->create();

    app(VerifyCompany::class)->execute($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Verified);
    expect($company->fresh()->activeBadges()->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
php artisan test tests/Feature/CompanyVerificationMirrorIntegrationTest.php
```
Expected: FAIL — no `Verification` row is created (mirror not wired in yet).

- [ ] **Step 3: Wire the mirror into `VerificationService`**

Add the constructor dependency and three call sites. Full updated constructor and methods:

```php
    public function __construct(
        private readonly BadgeService $badges,
        private readonly CompanyStatusService $companyStatus,
        private readonly CompanyVerificationMirror $mirror,
    ) {}
```

In `submit()`, after the existing `$this->companyStatus->submit($company, $actor);` call inside the transaction, and before the `activity(...)` line, add:

```php
            $this->mirror->submitted($company, $actor ?? $company->createdBy ?? User::query()->firstOrFail());
```

Wait — `submit()`'s `$actor` parameter is nullable (`?User $actor = null`), unlike the mirror's `approved()`/`rejected()`/`submitted()` signatures which require a `User`. Re-check: `CompanyVerificationMirror::submitted()` only needs `$actor` because `VerificationFlowService::open()` itself takes no `User` at all (`open(Model $entity): Verification` — no actor parameter). Simplify `CompanyVerificationMirror::submitted()`'s signature to drop the unused `$actor` parameter entirely rather than fabricating one:

```php
    public function submitted(Company $company): void
    {
        $this->safely(function () use ($company) {
            $this->flow->open($company);
        });
    }
```

Update the matching test in Task 3 (`it('opens a Verification row when a company is submitted for review')`) to call `->submitted($company)` with no `$actor` argument, and update `VerificationService::submit()`'s call site to:

```php
            $this->mirror->submitted($company);
```

placed directly after `$request = $company->verificationRequests()->create([...]);` inside the transaction (submission mirrors regardless of whether `CompanyStatusService::submit()` fires, since a re-submission of a `Pending` company still opens a fresh `VerificationRequest` today — confirm this reading against `submit()`'s existing early-return-if-open-request-exists behaviour, `VerificationService.php:32-34`, which means this new line only runs when a genuinely new request is created, matching the mirror's "open on submit" intent).

In `approve()`, after the existing `activity('compliance')->...->log('Verification approved');` line and before `return ['issued' => $issued, 'skipped' => $skipped];`, add:

```php
            $this->mirror->approved($company, $actor);
```

In `reject()`, after the existing `activity('compliance')->...->log('Verification rejected');` line (the method's last statement), add:

```php
        $this->mirror->rejected($company, $actor);
```

(`reject()` is not wrapped in `DB::transaction` today — confirm this by re-reading `VerificationService.php:151-170` before adding the call; place it as the final line of the method either way, outside any transaction, matching the method's existing structure.)

- [ ] **Step 4: Wire the mirror into `VerifyCompany`**

```php
<?php

namespace App\Actions\Company;

use App\Events\CompanyVerified;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyStatusService;
use App\Services\CompanyVerificationMirror;

class VerifyCompany
{
    public function __construct(
        private readonly CompanyStatusService $status,
        private readonly CompanyVerificationMirror $mirror,
    ) {}

    public function execute(Company $company, User $actor): Company
    {
        $company = $this->status->approve($company, $actor);

        CompanyVerified::dispatch($company, $actor);

        $this->mirror->approved($company, $actor);

        return $company;
    }
}
```

- [ ] **Step 5: Run tests, full suite, Pint, commit**

```bash
php artisan test tests/Feature/CompanyVerificationMirrorIntegrationTest.php
php artisan test tests/Feature/CompanyVerificationMirrorTest.php
php artisan test
vendor/bin/pint --dirty
git add app/Services/VerificationService.php app/Services/CompanyVerificationMirror.php app/Actions/Company/VerifyCompany.php tests/Feature/CompanyVerificationMirrorIntegrationTest.php tests/Feature/CompanyVerificationMirrorTest.php
git commit -m "Wire CompanyVerificationMirror into the 4 real Company verification mutation points"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 2.

---

### Task 5: Backfill — mirror `Verification` rows for existing companies

**Files:**
- Create: `app/Console/Commands/BackfillCompanyVerificationMirror.php`
- Test: `tests/Feature/BackfillCompanyVerificationMirrorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CompanyStatus;
use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\Verification;

it('backfills a mirrored Verification row for every non-draft company at the right terminal stage', function () {
    $verified = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $rejected = Company::factory()->create(['status' => CompanyStatus::Rejected]);
    $draft = Company::factory()->create(['status' => CompanyStatus::Draft]);

    $this->artisan('companies:backfill-verification-mirror')->assertSuccessful();

    expect(Verification::where('entity_type', Company::class)->where('entity_id', $verified->id)->first()->stage)->toBe(VerificationStage::Verified);
    expect(Verification::where('entity_type', Company::class)->where('entity_id', $rejected->id)->first()->stage)->toBe(VerificationStage::Rejected);
    expect(Verification::where('entity_type', Company::class)->where('entity_id', $draft->id)->exists())->toBeFalse();
});

it('is idempotent', function () {
    Company::factory()->create(['status' => CompanyStatus::Verified]);

    $this->artisan('companies:backfill-verification-mirror')->assertSuccessful();
    $firstCount = Verification::count();
    $this->artisan('companies:backfill-verification-mirror')->assertSuccessful();

    expect(Verification::count())->toBe($firstCount);
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
php artisan test tests/Feature/BackfillCompanyVerificationMirrorTest.php
```
Expected: FAIL — command `companies:backfill-verification-mirror` does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyVerificationMirror;
use Illuminate\Console\Command;

/**
 * One-time backfill of the read-only Company -> Verification mirror (item
 * 0.2b v2). Only covers pending/verified/rejected/suspended companies —
 * draft/archived companies never opened a real verification_requests row
 * and get none here either, matching the mirror's "opens on real submit"
 * semantics. suspended companies are mirrored at their last real stage
 * (verified), since the mirror has no suspended equivalent (see this
 * item's plan, Scope Decision) — this is a deliberate, documented
 * approximation, not a bug.
 */
class BackfillCompanyVerificationMirror extends Command
{
    protected $signature = 'companies:backfill-verification-mirror';

    protected $description = 'Backfill read-only Verification mirror rows for existing companies (gap-plan 0.2b v2)';

    public function handle(CompanyVerificationMirror $mirror): int
    {
        $actor = User::query()->first();

        if (! $actor) {
            $this->error('No User exists to attribute backfilled checkpoints to.');

            return self::FAILURE;
        }

        Company::query()
            ->whereIn('status', [
                CompanyStatus::Pending->value,
                CompanyStatus::Verified->value,
                CompanyStatus::Rejected->value,
                CompanyStatus::Suspended->value,
            ])
            ->chunkById(200, function ($companies) use ($mirror, $actor) {
                foreach ($companies as $company) {
                    $mirror->submitted($company);

                    if (in_array($company->status, [CompanyStatus::Verified, CompanyStatus::Suspended], true)) {
                        $mirror->approved($company, $actor);
                    } elseif ($company->status === CompanyStatus::Rejected) {
                        $mirror->rejected($company, $actor);
                    }
                }
            });

        $this->info('Company verification mirror backfill complete.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests, full suite, Pint, commit**

```bash
php artisan test tests/Feature/BackfillCompanyVerificationMirrorTest.php
php artisan test
vendor/bin/pint --dirty
git add app/Console/Commands/BackfillCompanyVerificationMirror.php tests/Feature/BackfillCompanyVerificationMirrorTest.php
git commit -m "Add companies:backfill-verification-mirror, idempotent, no fabricated sub-stage history"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 2.

---

### Task 6: Empirical end-to-end verification (tinker, real company, badges provably untouched)

- [ ] **Step 1: Before state** — via `php artisan tinker`, walk a real company through the OLD system exactly as today, and record `Event::fake()`-free, real output:

```php
$actor = App\Models\User::first();
$company = App\Models\Company::factory()->create(['status' => App\Enums\CompanyStatus::Draft]);
$docType = App\Models\DocumentType::where('key', 'business_registration')->first();
$doc = App\Models\CompanyDocument::factory()->for($company)->create(['document_type_id' => $docType->id]);
app(App\Services\VerificationService::class)->approveDocument($doc, $actor);

$service = app(App\Services\VerificationService::class);
$request = $service->submit($company, [App\Enums\BadgeType::VerifiedCompany->value]);
$service->startReview($request, $actor);
$result = app(App\Actions\Verification\ApproveVerificationRequest::class)->execute($request->fresh(), $actor);

$result; // expect ['issued' => ['verified_company'], 'skipped' => []]
$company->fresh()->activeBadges()->pluck('badge_type'); // expect ['verified_company']
$company->fresh()->status; // expect CompanyStatus::Verified
```

Report the actual observed values.

- [ ] **Step 2: Confirm the mirror, same walkthrough**

```php
$verification = App\Models\Verification::where('entity_type', App\Models\Company::class)->where('entity_id', $company->id)->first();
$verification->stage; // expect VerificationStage::Verified
$verification->checkpoints; // expect exactly 1 checkpoint, status Approved, notes mentioning "Mirrored"
```

Report the actual observed `stage` and checkpoint count/notes — this is the proof that the mirror landed at the correct stage from the real approval, in exactly one checkpoint, without altering `$result` or `activeBadges()` from Step 1.

- [ ] **Step 3: Confirm `VerifyCompany`'s badge-less path mirrors identically without issuing badges**

```php
$company2 = App\Models\Company::factory()->create(['status' => App\Enums\CompanyStatus::Pending]);
app(App\Actions\Company\VerifyCompany::class)->execute($company2, $actor);
$company2->fresh()->activeBadges()->count(); // expect 0
App\Models\Verification::where('entity_type', App\Models\Company::class)->where('entity_id', $company2->id)->first()->stage; // expect VerificationStage::Verified
```

Report the actual observed badge count (must be 0) and mirror stage (must be `Verified`) — proving the two independent dispatch sites identified in the Scope Decision both mirror correctly while keeping their genuinely different badge behaviour intact.

---

### Task 7: Record the outcome in `docs/GAP_PLAN.md`

**Lock protocol (shared file, other agents active in this worktree):**

```bash
mkdir C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
echo "verification-redesign-agent" > C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock\holder.txt
```

If `mkdir` fails (already locked), wait ~10s and retry.

- [ ] **Step 1: Re-read `docs/GAP_PLAN.md` fresh under the lock**, then update the `0.2b` row to point at this plan and mark it done at reduced scope (mirror only, badges/status untouched), and add a `0.2c` row for the deferred decision: whether `VerificationStage` should ever gain `suspended`/`archived` cases, or whether `Company`'s real lifecycle should simply never be represented in the shared framework beyond this read-only mirror.

- [ ] **Step 2: Commit and release the lock**

```bash
git add docs/GAP_PLAN.md
git commit -m "Mark 0.2b done at reduced (mirror-only) scope per v2 plan; track suspended/archived stage representation as 0.2c"
rm -rf C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
```

---

## Self-Review Notes

- **Spec coverage:** every one of the 4 real mutation points identified in the investigation (`VerificationService::submit/approve/reject`, `VerifyCompany::execute`) gets a mirror call; badge issuance and `companies.status`'s full 6-value machine are explicitly and correctly left untouched, with the reasoning (per-badge-type evaluation loop; no shared-enum representation for `suspended`/`archived`) documented rather than assumed away.
- **No fabricated history:** `fastForward()` records exactly one checkpoint per real decision, explicitly labelled as a mirrored jump, instead of inventing four intermediate sub-stage reviews that never happened for `Company`.
- **Failure isolation is load-bearing, not decorative:** `CompanyVerificationMirror::safely()` is tested (`it('never throws even if no open mirror row exists yet')`) precisely because this mirror must never be able to break the real verification/badge flow it observes.
- **Verify-before-code discipline:** Task 1 is pure investigation with explicit expected greps; Task 6 is a real tinker walkthrough with actual reported values, not a "tests passed" assertion, matching item 0.2's own empirical-verification precedent.
