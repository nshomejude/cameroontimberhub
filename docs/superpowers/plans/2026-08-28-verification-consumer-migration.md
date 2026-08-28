# Verification Consumer Migration Implementation Plan (0.2b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate `Company`/`verification_requests` (27 consumers) onto the polymorphic `Verification` framework built in item 0.2, keeping badge issuance (`CompanyVerified`/`BadgeIssued` events) firing at the exact same points in the new 8-stage sequence.

**Architecture:** `Verification`/`VerificationCheckpoint`/`VerificationFlowService` (item 0.2) already exist and are proven on `Product`. This plan adds `HasVerification` to `Company`, maps the OLD `companies.status` 6-value state machine and `verification_requests.status` 4-value state machine onto the NEW 8-stage `VerificationStage` sequence (a real mapping decision this plan makes explicitly, not silently), migrates each of the 27 consumers one at a time with its own test, and — critically — re-wires `CompanyStatusService`'s badge-issuance side effects (`CompanyVerified`, `BadgeIssued` events) to fire from `VerificationFlowService::publish()` instead of the old service's own transition method, so badge behaviour is provably unchanged.

**Tech Stack:** Laravel 13, Filament 5, Pest.

Reference: `docs/GAP_PLAN.md` item 0.2b, `docs/superpowers/plans/2026-08-27-polymorphic-verification.md` (item 0.2's own plan — its "Scope decision" section already enumerates every consumer file), `app/Services/CompanyStatusService.php`, `app/Services/VerificationService.php`, `app/Services/VerificationFlowService.php`, `app/Models/Company.php`, `app/Enums/VerificationStage.php`.

## Scope decision (read before executing)

**Full consumer inventory (confirmed by item 0.2's own plan, re-verify before starting):** `CompanyStatusService`, `VerificationService`, 3 `Actions\Verification\*` classes, the `VerificationRequests` Filament resource, `VerificationRequestPolicy`, `PendingVerificationsWidget`, the exporter panel's `EditCompany` page, and 8 Blade views. Re-run `grep -rln "companies\.status\|verification_requests\|CompanyStatusService\|->status ===.*CompanyStatus" app resources/views | wc -l` as your first step and reconcile against 27.

**The state-mapping decision, made explicitly here (do not re-litigate, but DO verify it against the real enum values first):** `companies.status` today is a 6-value CHECK (confirm the exact 6 values via `grep -n "companies_status_check" database/migrations/*.php` — do not assume). `VerificationStage` (item 0.2) has 9 cases: `registered, company_info, business_docs, identity_kyc, forestry_legal_docs, compliance_review, verified, rejected, published`. Map `draft`→`registered`, `pending`→(whichever mid-sequence stage the real `verification_requests` workflow actually represents — confirm by reading `VerificationService`'s current review logic, likely `compliance_review` or `business_docs`), `verified`→`verified` then immediately `published` (a verified company IS publicly listed today — confirm this is true by reading `Company::scopePubliclyVisible()`), `rejected`→`rejected`, and any other real status value found by the grep to whatever stage its actual behavior corresponds to. **Do not guess this mapping without reading the real enum and the real `CompanyStatusService::TRANSITIONS` constant first** (partially seen during planning: `'draft' => ['pending'], 'pending' => ['verified', 'rejected', 'draft']` — there may be more transitions than shown; read the whole constant).

**Badge-issuance preservation is the hard requirement, not a nice-to-have:** `CompanyVerified`/`BadgeIssued` events currently fire from wherever `CompanyStatusService` transitions a company to `verified`. After migration, they must fire from the equivalent `VerificationFlowService::publish()` (or `approve()` into the `verified` stage — confirm which transition is the actual "badge earned" moment by reading how the events are dispatched today) call, at the same logical point in the new sequence, not earlier or later. Task 3's own test proves this with a real before/after event-firing comparison, not just a passing status check.

---

### Task 1: Wire `HasVerification` onto `Company`, define the stage mapping

**Files:**
- Modify: `app/Models/Company.php`
- Create: `app/Support/CompanyStatusMigrationMap.php` (mirrors `SupplierTypeMigrationMap`'s house pattern from item 0.6 — a single, explicit, well-documented mapping array, not scattered inline logic)
- Test: `tests/Feature/CompanyVerificationMappingTest.php`

- [ ] **Step 1: Verify against reality first**

```bash
grep -n "companies_status_check" database/migrations/*.php
grep -n "verification_requests.*_check" database/migrations/*.php
grep -n "TRANSITIONS" -A 15 app/Services/CompanyStatusService.php
grep -n "scopePubliclyVisible" -A 5 app/Models/Company.php
```

Report the real exact value lists before proceeding — the mapping in this plan's Scope Decision section is a starting hypothesis, not a guaranteed-correct final answer.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\VerificationStage;
use App\Support\CompanyStatusMigrationMap;

it('maps every real companies.status value to a real VerificationStage case', function () {
    foreach (CompanyStatusMigrationMap::MAP as $oldStatus => $newStage) {
        expect(VerificationStage::tryFrom($newStage))->not->toBeNull("companies.status={$oldStatus} maps to an invalid stage: {$newStage}");
    }
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/CompanyVerificationMappingTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the mapping class**, using the REAL values confirmed in Step 1 (do not paste the Scope Decision's hypothesis unverified):

```php
<?php

namespace App\Support;

/**
 * companies.status (old, 6-value CHECK) -> VerificationStage (item 0.2's
 * 9-case enum), the single source of truth for item 0.2b's migration.
 * Extend/correct this array, not the migration logic elsewhere, if the
 * mapping needs to change.
 */
final class CompanyStatusMigrationMap
{
    /** @var array<string, string> */
    public const MAP = [
        // Fill in with the REAL values confirmed in Step 1.
    ];
}
```

- [ ] **Step 5: Add `HasVerification` to `Company`**

Confirm first (per the pattern that recurred with `Species`/`Product` in items 0.1/0.2) whether `Company` already has a stopgap `verification()`/`status`-reading method that would collide with the trait — remove it if so, exactly as those earlier collisions were resolved.

- [ ] **Step 6: Run tests, Pint, commit**

```bash
php artisan test tests/Feature/CompanyVerificationMappingTest.php
vendor/bin/pint --dirty
git add app/Models/Company.php app/Support/CompanyStatusMigrationMap.php tests/Feature/CompanyVerificationMappingTest.php
git commit -m "Wire HasVerification onto Company and define the companies.status -> VerificationStage mapping"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this, it has silently wiped the dev database before in this project.

---

### Task 2: Backfill — create a `Verification` row per existing `Company`

**Files:**
- Create: `app/Console/Commands/BackfillCompanyVerifications.php`
- Test: `tests/Feature/BackfillCompanyVerificationsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Verification;

it('creates one Verification row per company at the mapped stage', function () {
    $verified = Company::factory()->create(['status' => CompanyStatus::Verified]);

    $this->artisan('companies:backfill-verifications')->assertSuccessful();

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $verified->id)->first();
    expect($verification)->not->toBeNull();
});

it('is idempotent', function () {
    $company = Company::factory()->create();
    $this->artisan('companies:backfill-verifications')->assertSuccessful();
    $firstCount = Verification::count();
    $this->artisan('companies:backfill-verifications')->assertSuccessful();

    expect(Verification::count())->toBe($firstCount);
});
```

- [ ] **Step 2: Run to verify it fails, then write the command**

Use `VerificationFlowService::open()` (already idempotent per item 0.2's own design — "finds existing non-terminal or creates new") rather than inserting `Verification` rows directly, so the same state-machine invariants (partial unique "one open verification per entity" index) apply to backfilled data exactly as they do to newly-created data. For each company already at a terminal-equivalent old status (`verified`/`published`-equivalent, `rejected`-equivalent), advance the freshly-opened `Verification` through `approve()` calls to the mapped stage, or `reject()` if mapped to `rejected` — do not fabricate checkpoint history that didn't happen; a backfilled row's checkpoints legitimately start now, at backfill time, not retroactively dated.

- [ ] **Step 3: Run tests, full suite, Pint, commit**

```bash
php artisan test tests/Feature/BackfillCompanyVerificationsTest.php
php artisan test
vendor/bin/pint --dirty
git add app/Console/Commands/BackfillCompanyVerifications.php tests/Feature/BackfillCompanyVerificationsTest.php
git commit -m "Add companies:backfill-verifications, idempotent, using VerificationFlowService's own state machine"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Re-wire badge issuance to fire from `VerificationFlowService`

**Files:**
- Modify: wherever `CompanyVerified`/`BadgeIssued` are currently dispatched (find via `grep -rln "CompanyVerified\|BadgeIssued" app`)
- Modify: `app/Services/VerificationFlowService.php` (add the dispatch at the equivalent transition, confirmed in this plan's Scope Decision)
- Test: extend the existing test file covering these events (find via `grep -rln "CompanyVerified\|BadgeIssued" tests`)

- [ ] **Step 1: Read the current dispatch site in full** and identify the exact old-status transition that triggers it today.

- [ ] **Step 2: Write a failing test** proving the event fires from the NEW code path (`VerificationFlowService::approve()`/`publish()` reaching the mapped equivalent stage) — a real `Event::fake()` assertion, not a status-value check.

- [ ] **Step 3: Move the dispatch**, keeping the OLD dispatch site in place but gated so it does not double-fire once the company has a `Verification` row (avoid duplicate badge-issuance events during the transition period where both systems could theoretically fire) — or remove the old dispatch entirely if Task 1–2 already fully replaced the old status-transition code path for every consumer that would have triggered it. Decide based on what Task 4/5's actual consumer migration leaves in place; do not guess before doing those tasks — this task may need to be finished last if it depends on their outcome.

- [ ] **Step 4: Run tests, full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git commit -m "Re-wire CompanyVerified/BadgeIssued to fire from VerificationFlowService at the equivalent transition"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 4: Migrate the 27 consumers, one at a time

Same discipline as the sibling 0.1b plan's Task 3: for each of `CompanyStatusService`, `VerificationService`, the 3 `Actions\Verification\*` classes, the `VerificationRequests` Filament resource, `VerificationRequestPolicy`, `PendingVerificationsWidget`, the exporter `EditCompany` page, and the 8 Blade views — read the file, migrate its status/stage reads and writes to `Company`'s new `Verification` relation (via `HasVerification`/`VerificationFlowService`), run its own test, commit separately. Order: read-only consumers (Blade views, the widget) first, the Filament resource and policy next, the actions and services last (most logic-dense, migrate with the most care). Run the full suite once after every consumer is migrated.

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 5: Empirical end-to-end verification

Via tinker, walk one real `Company` through the full mapped sequence and confirm badge issuance fires at the correct point — report actual observed event-firing and stage values, not just "tests passed," mirroring exactly how item 0.2's original plan proved its own sequence empirically.

---

### Task 6: Record the deferred table-drop decision

Same pattern as the sibling 0.1b plan's Task 6 — mark `0.2b` done in `docs/GAP_PLAN.md`, add a `0.2c` row tracking the eventual drop of `companies.status`/`verification_requests` as its own explicit-sign-off decision, not a default follow-on.

```bash
git add docs/GAP_PLAN.md
git commit -m "Mark 0.2b done; track dropping the legacy company-status columns as 0.2c, pending explicit sign-off"
```

---

## Self-Review Notes

- **Spec coverage:** all 27 consumers addressed; the state-mapping decision is made explicitly and verifiably (Task 1's test asserts every real old value maps to a real new stage) rather than assumed; badge-issuance preservation is a dedicated task with its own event-firing test, not an incidental side effect of the migration.
- **No fabricated history:** the backfill (Task 2) advances companies through the real state machine at backfill time rather than inventing retroactive checkpoint timestamps.
- **Verify-before-code discipline:** every task's first step is confirming real enum values, constants, and dispatch sites before writing code.
