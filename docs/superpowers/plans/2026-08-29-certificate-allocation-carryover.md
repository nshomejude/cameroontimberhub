# Certificate Allocation Carry-Over Implementation Plan (0.8c, part 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the first of item 0.8c's two known limitations — `certificate_allocations` rows reference one specific certificate *version* row, so `CertificateAllocationService::remaining()` doesn't see allocations made against a prior version once `CertificateService::createVersion()` supersedes it, allowing the same certified quantity to be double-claimed across a version boundary.

**Architecture, and the decision made here:** the two options were "a re-issued version inherits its predecessor's claimed quantity" or "starts clean." **Decided: inherits.** A certificate version is the same underlying commercial claim (same `certificate_number`, same `certified_quantity` copied forward by `CertificateService::createVersion()`) — a correction to the product description or geospatial record does not un-claim timber that was already allocated to a real shipment under the prior version. `remaining()` is changed to sum allocations across every row sharing the same `certificate_number` (the whole version chain), not just the current row's `certificate_id` — additive, no schema change, no migration needed.

**Tech Stack:** Laravel 13, Pest.

Reference: `app/Services/CertificateAllocationService.php`, `app/Models/Certificate.php` (`scopeForNumber()`), `database/migrations/2026_08_29_100001_create_certificate_allocations_table.php`'s docblock (documents this exact limitation), `docs/GAP_PLAN.md` item 0.8c.

**Module boundary (do not touch anything outside this list):** `app/Services/CertificateAllocationService.php` only, plus its test file. Do NOT touch `CertificateService.php`, `CertificateSigningService.php` (the KMS/HSM half of 0.8c is explicitly NOT this plan's scope — leave it for a separate, later item), `Certificate.php`, or any other Certificate* file — those belong to other concurrently-dispatched agents' modules or are out of scope entirely.

---

### Task 1: Sum allocations across the full version chain, not just one row

**Files:**
- Modify: `app/Services/CertificateAllocationService.php`
- Test: `tests/Feature/CertificateAllocationCarryOverTest.php`

- [ ] **Step 1: Verify against reality first**

Read `app/Services/CertificateAllocationService.php` in full (already shown during planning — confirm `remaining()`'s exact current query: `CertificateAllocation::query()->where('certificate_id', $certificate->getKey())->sum('quantity')`). Read `Certificate::scopeForNumber()` and confirm `certificate_number` is shared across every version row (it is, per item 0.8's own design).

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Certificate;
use App\Models\CertificateAllocation;
use App\Models\Quote;
use App\Services\CertificateAllocationService;

beforeEach(function () {
    $this->service = app(CertificateAllocationService::class);
});

it('carries allocations forward across a version boundary, so a re-issued certificate cannot double-claim already-allocated quantity', function () {
    $v1 = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3', 'certificate_number' => 'TH-CARRY-TEST-01', 'version' => 1]);
    $this->service->allocate($v1, Quote::factory()->create(), 60);

    // Simulate createVersion(): a new row, same certificate_number, same
    // certified_quantity, v1 marked superseded (status change not needed
    // for this test -- only the shared certificate_number matters here).
    $v2 = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3', 'certificate_number' => 'TH-CARRY-TEST-01', 'version' => 2, 'previous_version_id' => $v1->id]);

    expect($this->service->remaining($v2))->toEqual(40);

    // The remaining 40 is genuinely allocatable against the NEW version...
    $this->service->allocate($v2, Quote::factory()->create(), 40);
    expect($this->service->remaining($v2))->toEqual(0);

    // ...but no more, even though $v2's own certificate_allocations rows
    // only sum to 40 -- the v1 allocation of 60 must still count.
    expect(fn () => $this->service->allocate($v2, Quote::factory()->create(), 1))
        ->toThrow(RuntimeException::class, 'exceeds remaining');
});

it('still works correctly for a certificate with only one version (no regression)', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50, 'quantity_unit' => 'm3']);
    $this->service->allocate($certificate, Quote::factory()->create(), 20);

    expect($this->service->remaining($certificate))->toEqual(30);
});
```

Confirm `Quote::factory()` exists (used elsewhere in this codebase's certificate tests per item 0.8's own Task 7 correction — it substituted `Quote` for `Order` as the allocation consumer since `Order` has no factory). If it doesn't, use whatever real factory-backed model item 0.8's own `CertificateAllocationServiceTest.php` actually used — read that file first to match its exact consumer choice.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateAllocationCarryOverTest.php`
Expected: FAIL — the first test's final assertion doesn't throw (v2 alone only sees 40 allocated, so it would wrongly allow allocating another 40+).

- [ ] **Step 4: Fix `remaining()`**

```php
    public function remaining(Certificate $certificate): float
    {
        // Sums allocations across every version sharing this
        // certificate_number, not just this row's own certificate_id --
        // a version is the same underlying commercial claim
        // (docs/GAP_PLAN.md item 0.8c: inherits, does not start clean).
        $allocated = (float) CertificateAllocation::query()
            ->whereIn('certificate_id', Certificate::query()->where('certificate_number', $certificate->certificate_number)->pluck('id'))
            ->sum('quantity');

        return (float) $certificate->certified_quantity - $allocated;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CertificateAllocationCarryOverTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Full suite — confirm the existing `CertificateAllocationServiceTest.php` (item 0.8's own) still passes unchanged**

```bash
php artisan test
```

Expected: green, including every pre-existing allocation test (single-version behavior is a strict subset of the new query and should be unaffected).

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/CertificateAllocationService.php tests/Feature/CertificateAllocationCarryOverTest.php
git commit -m "Carry allocations forward across certificate version boundaries, closing half of 0.8c"
```

---

### Task 2: Update the docblock and gap-plan entry

**Files:**
- Modify: `database/migrations/2026_08_29_100001_create_certificate_allocations_table.php` (the docblock's "KNOWN LIMITATION" note — update it to say the carry-over half is resolved, only the KMS/HSM half of 0.8c remains)
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1:** Update the migration file's docblock (do not touch the actual `up()`/`down()` schema — this is a comment-only change).
- [ ] **Step 2:** Re-read `docs/GAP_PLAN.md` fresh (other agents may be editing it concurrently — treat as contested) and update item `0.8c` to note the allocation carry-over half is done, only the KMS/HSM signing upgrade remains open.
- [ ] **Step 3:** Commit.

```bash
git add database/migrations/2026_08_29_100001_create_certificate_allocations_table.php docs/GAP_PLAN.md
git commit -m "Mark 0.8c's allocation carry-over half done; KMS/HSM upgrade remains open"
```

No test run needed for this task beyond Task 1's own.

---

## Self-Review Notes

- **Module boundary respected:** only `CertificateAllocationService.php` and its test are modified in Task 1; Task 2 touches only a docblock and the gap-plan doc. No other agent's module (documents, consent, RBAC, activity log) is referenced.
- **Decision stated plainly, not silently:** "inherits" vs "starts clean" is decided and justified in the Architecture section, matching every other product decision this session (manual billing, exporter/trader mapping) — stated to the user, not buried.
- **No scope creep into the KMS/HSM half:** explicitly out of scope, left for a separate item once a real KMS provider decision exists.
