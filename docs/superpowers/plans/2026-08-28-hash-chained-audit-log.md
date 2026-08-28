# Hash-Chained Audit Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the genuinely available part of gap-plan item 0.5 — a hash-chain over `spatie/laravel-activitylog`'s `activity_log` table, giving the platform a tamper-evident audit trail (§3.9's own stated need, and one of §6.9's four requirements: "all actions in audit log," made tamper-evident). This is scoped down from the full 0.5 item — see Scope Decision.

**Architecture:** `activity_log` already exists (installed, used by `Certificate` as of item 0.8) and is append-heavy with no update/delete path in normal operation. Add two nullable columns, `hash` and `prev_hash`, computed in a model event exactly like `Document`'s existing `hash`/`prev_hash` chain (`app/Models/Document.php::booted()`) — the same proven pattern, applied to a second model. A verification command walks the chain and reports the first break, if any.

**Tech Stack:** Laravel 13, `spatie/laravel-activitylog`, Pest.

Reference: `docs/GAP_PLAN.md` item 0.5, `CTH_Claude_Code_Build_Brief.md` §3.9 and §6.9, `app/Models/Document.php` (the existing hash-chain pattern this plan reuses), `database/migrations/2026_06_22_090514_create_activity_log_table.php`.

## Scope decision (read before executing)

**What §6.9 actually asks for, and why most of it is out of scope here:** "Separation of duties (enforced in code): originator ≠ approver · compliance can block at any stage · field verification independent of commercial negotiation · disbursement requires dual authorisation by amount band · conflicts recorded on every committee decision · exceptions need documented senior approval · all actions in audit log." Every clause except the last ("all actions in audit log") describes approval/disbursement/committee workflows belonging to the Forest Sponsorship & Strategic Investment program (brief §6) — `project` submissions, funding agreements, tranche disbursements, investment-committee decisions. **None of that subsystem exists in the codebase today** (`grep -rli "sponsorship\|disbursement\|funding_agreement\|investment_committee" app database/migrations` — confirm this yourself before starting; expect no hits). Building "originator ≠ approver enforcement" or "dual-authorisation by amount band" against a disbursement workflow that doesn't exist would mean inventing both the enforcement AND the thing being enforced, which is exactly the fabricated-infrastructure mistake this project's rules exist to prevent — the same reasoning that deferred the certificate's physical-production layer (item 0.8b) and the pricing page's billing engine (item 0.9b).

**What's genuinely buildable now:** the audit-log tamper-evidence primitive itself. It has zero dependency on the Forest Sponsorship subsystem existing — it strengthens the SAME `activity_log` table every current and future feature already logs into (RFQ triage, subscription assignment, certificate lifecycle, etc.), and directly satisfies §3.9's own documented need for this hash-chain primitive (the audit `docs/AUDIT.md` and multiple item-0.1–0.4 plans this session already noted "gives §3.9 its hash-chain primitive for free" as a stated benefit of the `Document` hash-chain — this plan is that promise, delivered for the general audit trail rather than just documents).

**Explicitly deferred, tracked as `0.5b`:** originator≠approver enforcement, compliance-block capability, dual-authorisation by amount band, committee-decision conflict recording, and documented-exception approval — all blocked on the Forest Sponsorship subsystem (brief §6) actually being built first, which is a large separate Phase 2 item, not a Phase 0 foundation.

---

### Task 1: `hash`/`prev_hash` columns on `activity_log`, computed on creation

**Files:**
- Create: `database/migrations/2026_08_28_100040_add_hash_chain_to_activity_log_table.php`
- Create: `app/Models/ChainedActivity.php` (extends Spatie's `Activity` model to add the chaining behaviour — see Step 3 for why a subclass, not a direct edit to vendor code)
- Modify: `app/Providers/AppServiceProvider.php` (or wherever `spatie/laravel-activitylog`'s config binds its model class — confirm via `config/activitylog.php`'s `activity_model` key) to use `ChainedActivity` instead of the package default
- Test: `tests/Feature/ActivityLogHashChainTest.php`

- [ ] **Step 1: Verify against reality first**

```bash
grep -n "activity_model" config/activitylog.php
grep -rli "sponsorship\|disbursement\|funding_agreement\|investment_committee" app database/migrations
```

Confirm `config/activitylog.php` has an `activity_model` key you can override (this is how `spatie/laravel-activitylog` lets you swap in a custom model class — check the package's actual published config, don't assume the key name without checking), and confirm the second grep finds nothing (validating this plan's Scope Decision). Report both findings even if they match expectations.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\ChainedActivity;
use App\Models\Certificate;
use App\Models\User;

it('computes a hash and chains prev_hash to the immediately preceding activity row, globally in insertion order', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();

    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_a');
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_b');

    $rows = ChainedActivity::orderBy('id')->get();
    $a = $rows->firstWhere('description', 'event_a');
    $b = $rows->firstWhere('description', 'event_b');

    expect($a->hash)->not->toBeNull()->and($a->hash)->toHaveLength(64)
        ->and($b->prev_hash)->toBe($a->hash);
});

it('produces a different hash when a chained field actually differs between two otherwise-similar rows', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();

    activity('cert')->performedOn($certificate)->causedBy($actor)->log('same_description');
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('same_description');

    $rows = ChainedActivity::where('description', 'same_description')->orderBy('id')->get();

    expect($rows[0]->hash)->not->toBe($rows[1]->hash);
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/ActivityLogHashChainTest.php`
Expected: FAIL — `Class "App\Models\ChainedActivity" not found`.

- [ ] **Step 4: Write the migration**

Mirror the shape of `Document`'s `hash`/`prev_hash` columns exactly (`database/migrations/2026_08_28_100010_create_documents_table.php` — read it first to match column type/length precisely):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident hash chain over the activity log (gap-plan 0.5, brief
 * §3.9 / §6.9's "all actions in audit log" clause). Mirrors Document's
 * existing hash/prev_hash pattern (app/Models/Document.php) applied to a
 * second, general-purpose model. The chain is GLOBAL (one sequence across
 * every logged activity, in insertion order) rather than per-subject,
 * because the audit trail's integrity claim is "nothing was inserted,
 * deleted, or reordered in this table," not "this one entity's history is
 * intact" -- a global chain is the only shape that can prove that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->char('hash', 64)->nullable()->after('properties');
            $table->char('prev_hash', 64)->nullable()->after('hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn(['hash', 'prev_hash']);
        });
    }
};
```

- [ ] **Step 5: Write the model subclass**

A subclass, not a direct edit to the vendor `Spatie\Activitylog\Models\Activity` class, so a future `composer update` of the package cannot silently drop this behaviour:

```php
<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * Adds a tamper-evident hash chain to spatie/laravel-activitylog's Activity
 * model (gap-plan 0.5). Registered as the package's `activity_model` in
 * config/activitylog.php so every activity() call across the app -- RFQ
 * triage, subscription assignment, certificate lifecycle, and any future
 * consumer -- is chained automatically, with no per-call-site change needed.
 *
 * Chains GLOBALLY in insertion order (not per-subject) -- see the migration
 * comment for why. hash covers this row's own core fields plus the
 * immediately preceding row's hash, so altering, deleting, or reordering
 * any historical row breaks every hash after it, detectable by
 * `activitylog:verify-chain`.
 */
class ChainedActivity extends Activity
{
    protected static function booted(): void
    {
        static::creating(function (ChainedActivity $activity) {
            $previous = static::query()->orderByDesc('id')->first();
            $activity->prev_hash = $previous?->hash;

            $activity->hash = hash('sha256', json_encode([
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'properties' => $activity->properties?->toArray(),
                'prev_hash' => $activity->prev_hash,
            ], JSON_THROW_ON_ERROR));
        });
    }
}
```

- [ ] **Step 6: Register the custom model**

In `config/activitylog.php`, find the `activity_model` key (confirmed present by Step 1) and change its default to `App\Models\ChainedActivity::class` — if the key reads its value from an env var, update the fallback/default, not just the literal value, so this survives `config:cache`.

- [ ] **Step 7: Run tests to verify they pass**

```bash
php artisan migrate --force
php artisan test tests/Feature/ActivityLogHashChainTest.php
```

Expected: PASS (2 tests).

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this, it has silently wiped the dev database before in this project.

- [ ] **Step 8: Full suite — confirm every EXISTING activity() call site still works**

```bash
php artisan test
```

Expected: green, including every pre-existing test that touches `activity()` (item 0.8's Certificate activity-log tests, `RfqTriageService`, `SubscriptionService`). If any fail, read the failure carefully before assuming the chain logic is the cause — it's more likely a config-binding issue (Step 6) than a logic bug, given the chain only adds two nullable columns and never rejects a write.

- [ ] **Step 9: Pint and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_28_100040_add_hash_chain_to_activity_log_table.php \
        app/Models/ChainedActivity.php config/activitylog.php tests/Feature/ActivityLogHashChainTest.php
git commit -m "Hash-chain the activity log (gap-plan 0.5): tamper-evident audit trail, reusing Document's chain pattern"
```

---

### Task 2: A verification command that walks the chain

**Files:**
- Create: `app/Console/Commands/VerifyActivityLogChain.php`
- Test: `tests/Feature/VerifyActivityLogChainTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ChainedActivity;
use App\Models\Certificate;
use App\Models\User;

it('reports the chain intact when nothing has been tampered with', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_a');
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_b');

    $this->artisan('activitylog:verify-chain')
        ->assertSuccessful()
        ->expectsOutputToContain('Chain intact');
});

it('detects and reports the first row where the chain breaks', function () {
    $actor = User::factory()->create();
    $certificate = Certificate::factory()->create();
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_a');
    $tampered = ChainedActivity::latest('id')->first();
    activity('cert')->performedOn($certificate)->causedBy($actor)->log('event_b');

    // Simulate tampering: rewrite a historical row's description without
    // recomputing its hash (exactly what an attacker with raw DB access,
    // bypassing Eloquent, would do).
    \Illuminate\Support\Facades\DB::table('activity_log')->where('id', $tampered->id)->update(['description' => 'event_a_altered']);

    $this->artisan('activitylog:verify-chain')
        ->assertFailed()
        ->expectsOutputToContain((string) $tampered->id);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/VerifyActivityLogChainTest.php`
Expected: FAIL — command `activitylog:verify-chain` does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\ChainedActivity;
use Illuminate\Console\Command;

/**
 * Walks the activity log's hash chain (gap-plan 0.5) in insertion order and
 * reports the first row whose stored hash no longer matches what its own
 * fields (plus the previous row's hash) recompute to -- proof that some row
 * was altered after being written, bypassing Eloquent (a raw UPDATE, a
 * restored backup missing later rows, etc.).
 */
class VerifyActivityLogChain extends Command
{
    protected $signature = 'activitylog:verify-chain';

    protected $description = 'Verify the activity log hash chain has not been tampered with';

    public function handle(): int
    {
        $previousHash = null;

        foreach (ChainedActivity::orderBy('id')->cursor() as $activity) {
            if ($activity->hash === null) {
                // Rows created before this chain existed -- not a tamper
                // signal, just pre-chain history. Skip and reset the
                // expected previous hash so the chain resumes cleanly from
                // the first chained row.
                $previousHash = null;

                continue;
            }

            if ($activity->prev_hash !== $previousHash) {
                $this->error("Chain broken at activity #{$activity->id}: expected prev_hash [{$previousHash}], found [{$activity->prev_hash}].");

                return self::FAILURE;
            }

            $expectedHash = hash('sha256', json_encode([
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'properties' => $activity->properties?->toArray(),
                'prev_hash' => $activity->prev_hash,
            ], JSON_THROW_ON_ERROR));

            if ($expectedHash !== $activity->hash) {
                $this->error("Chain broken at activity #{$activity->id}: stored hash does not match its own recomputed fields. This row's data was altered after being logged.");

                return self::FAILURE;
            }

            $previousHash = $activity->hash;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/VerifyActivityLogChainTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Console/Commands/VerifyActivityLogChain.php tests/Feature/VerifyActivityLogChainTest.php
git commit -m "Add activitylog:verify-chain to detect tampering in the hash-chained audit log"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Record the deferred separation-of-duties scope and self-review

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Read the current Phase 0 table first** (item numbers may have shifted) and replace the existing `0.5` row:

```markdown
| 0.5 | ✅ **Hash-chained audit log — done.** `activity_log.hash`/`prev_hash`, computed on every write via `ChainedActivity` (a subclass registered as `spatie/laravel-activitylog`'s `activity_model`, so every existing and future `activity()` call site is chained with no per-call-site change), plus `activitylog:verify-chain` to detect tampering. Scoped down from the full item — see "0.5b" below. Plan: `docs/superpowers/plans/2026-08-28-hash-chained-audit-log.md`. | — | 0 (done) |
| 0.5b | **Forest Sponsorship separation of duties.** The rest of §6.9 — originator≠approver enforcement, `compliance_officer` block capability, dual-authorisation by amount band, committee-decision conflict recording, documented-exception approval. All of it governs the Forest Sponsorship & Strategic Investment program (brief §6: project applications, funding agreements, tranche disbursements, investment-committee decisions), none of which exists in the codebase yet (confirmed by grep during 0.5's planning). Building enforcement for workflows that don't exist would fabricate both halves. Blocked on §6 itself being built (a large, separate Phase 2 item). | §6.9 requires it in code once §6 exists; premature before then. | Blocked — depends on the Forest Sponsorship subsystem (§6) existing first |
```

- [ ] **Step 2: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Mark the hash-chained audit log (0.5) done; split Forest Sponsorship separation-of-duties out as 0.5b, blocked on §6"
```

No test run needed — documentation only.

---

## Self-Review Notes

- **Spec coverage:** the one clause of §6.9 that has no dependency on an unbuilt subsystem — "all actions in audit log," made tamper-evident — is fully covered (Tasks 1–2). Every other clause is honestly deferred with a stated, investigated reason (Task 3), not silently dropped.
- **No fabricated infrastructure:** no disbursement/committee/approval workflow is invented to have something to enforce separation-of-duties against.
- **Pattern reuse, not a new convention:** `ChainedActivity`'s hashing logic deliberately mirrors `Document::booted()`'s existing hash-chain shape (same house pattern, second consumer) rather than inventing a different chaining scheme for the same underlying idea.
