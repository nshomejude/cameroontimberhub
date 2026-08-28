# Document Consumer Migration Implementation Plan (0.1b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate `CompanyDocument` and `OrderDocument`'s ~28 consumers onto the polymorphic `Document` store built in item 0.1, without ever leaving a gap where a document is briefly invisible to any consumer mid-migration.

**Architecture:** `Document` (item 0.1) already exists, hash-chains, and has `HasDocuments`/`DocumentPolicy`. This plan does NOT drop `company_documents`/`order_documents` tables — it (a) generalizes `DocumentReminderLog` to a polymorphic `owner_type`/`owner_id` shape (currently hard-FKs `company_document_id`), (b) writes a backfill command copying every existing `CompanyDocument`/`OrderDocument` row into `documents` with `owner_type` set to `Company`/`Order` respectively, preserving original `id` order for hash-chain correctness, (c) migrates each of the ~28 consumers to read/write `Document` via the `HasDocuments` trait instead of the old dedicated relation, one file at a time with its own test run, and (d) only once every consumer is confirmed migrated and passing, marks `CompanyDocument`/`OrderDocument` deprecated (not dropped — dropping tables/models is a separate, later decision requiring its own sign-off, out of scope here).

**Tech Stack:** Laravel 13, Filament 5, Pest.

Reference: `docs/GAP_PLAN.md` item 0.1b, `docs/superpowers/plans/2026-08-27-polymorphic-document-store.md` (item 0.1's own plan — read its "Scope decision" section again, it already enumerates every consumer file this plan must touch), `app/Models/Document.php`, `app/Models/Concerns/HasDocuments.php`, `app/Models/CompanyDocument.php`, `app/Models/OrderDocument.php`, `app/Jobs/SendDocumentExpiryReminderJob.php`, `database/migrations/2026_06_22_110040_create_document_reminder_logs_table.php`.

## Scope decision (read before executing)

**Full consumer inventory (confirmed by item 0.1's own plan, re-verify counts before starting since time has passed):** `CompanyDocument` — two Filament panels (admin + exporter document resources/relation managers), 4 actions, 2 events, `SendDocumentExpiryReminderJob`, a notification, a policy, 3 services. `OrderDocument` — 4+ consumers (order document upload/download flow). Re-run `grep -rln "CompanyDocument\b" app | wc -l` and `grep -rln "OrderDocument\b" app | wc -l` as your first step and reconcile against these counts before planning your task order — if the numbers differ meaningfully, report that as a finding rather than silently working from stale numbers.

**Why the reminder log must move first, not last:** `DocumentReminderLog.company_document_id` is `NOT NULL` and FK-constrained to `company_documents`. Every other consumer migration is reversible/incremental (read from `Document` instead of `CompanyDocument`, same data either way once backfilled) — but `SendDocumentExpiryReminderJob` cannot fire correctly for a `Document`-owned entity until `DocumentReminderLog` can reference something other than `company_documents`. This is why Task 1 (the polymorphic reminder log) comes before any consumer migration, not after.

**Backfill correctness constraint, carried over from item 0.1's own design:** `Document::booted()`'s `creating` hook computes `hash`/`prev_hash` **per owner, in upload order**. The backfill command (Task 2) MUST insert rows in each owner's original `created_at`/`id` order — a batch insert or an out-of-order backfill would silently produce a broken or meaningless hash chain. Verify this empirically (Task 2's own test asserts a real multi-document chain from backfilled data resolves correctly), not just by reading the code.

**What "migrated" means for each consumer, precisely:** the consumer reads/writes via `$owner->documents()` (the `HasDocuments` trait relation) instead of `$owner->companyDocuments()`/`$owner->orderDocuments()` (or whatever the old dedicated relation is actually called — confirm the real name before assuming). The OLD `company_documents`/`order_documents` tables and their Eloquent models are NOT dropped, NOT deprecated with warnings, and NOT write-blocked by this plan — that's explicitly a separate, later decision (a genuine breaking change needing its own sign-off), tracked as `0.1c` in Task 6.

---

### Task 1: Generalize `DocumentReminderLog` to a polymorphic owner

**Files:**
- Create: `database/migrations/2026_08_28_XXXXXX_make_document_reminder_logs_polymorphic.php` (pick the next free timestamp after the most recent migration file — check `ls database/migrations | tail -1` first)
- Modify: `app/Models/DocumentReminderLog.php`
- Modify: `app/Jobs/SendDocumentExpiryReminderJob.php`
- Test: `tests/Feature/DocumentReminderLogPolymorphicTest.php`

- [ ] **Step 1: Verify against reality first**

Read `app/Models/DocumentReminderLog.php`, `database/migrations/2026_06_22_110040_create_document_reminder_logs_table.php`, and `app/Jobs/SendDocumentExpiryReminderJob.php` in full. Confirm the exact current column name (`company_document_id`), the unique constraint shape (`['company_document_id', 'threshold']`), and exactly how the job queries for documents needing a reminder — this determines what the polymorphic replacement query must look like.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Document;
use App\Models\DocumentReminderLog;
use App\Models\Species;

it('records a reminder log against either a CompanyDocument or a polymorphic Document owner', function () {
    $companyDoc = CompanyDocument::factory()->create();
    $log1 = DocumentReminderLog::create([
        'document_owner_type' => CompanyDocument::class,
        'document_owner_id' => $companyDoc->id,
        'threshold' => 30,
        'sent_at' => now(),
    ]);

    $species = Species::factory()->create();
    $document = Document::factory()->create(['owner_type' => Species::class, 'owner_id' => $species->id]);
    $log2 = DocumentReminderLog::create([
        'document_owner_type' => Document::class,
        'document_owner_id' => $document->id,
        'threshold' => 30,
        'sent_at' => now(),
    ]);

    expect($log1->fresh())->not->toBeNull()->and($log2->fresh())->not->toBeNull();
});

it('enforces the unique (document_owner_type, document_owner_id, threshold) constraint', function () {
    $species = Species::factory()->create();
    $document = Document::factory()->create(['owner_type' => Species::class, 'owner_id' => $species->id]);
    DocumentReminderLog::create(['document_owner_type' => Document::class, 'document_owner_id' => $document->id, 'threshold' => 30, 'sent_at' => now()]);

    expect(fn () => DocumentReminderLog::create(['document_owner_type' => Document::class, 'document_owner_id' => $document->id, 'threshold' => 30, 'sent_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

Adjust column names (`document_owner_type`/`document_owner_id` is this plan's proposed naming — keep it consistent with the rest of the migration and model code you write, but confirm it doesn't collide with an already-reserved name in the table) to match whatever Step 1 confirms is cleanest given the real current schema.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/DocumentReminderLogPolymorphicTest.php`
Expected: FAIL — the polymorphic columns don't exist yet.

- [ ] **Step 4: Write the migration**

Additive-first, not a destructive rename: add `document_owner_type`/`document_owner_id` as new nullable columns, backfill them from the existing `company_document_id` (every existing row's owner is unambiguously `CompanyDocument::class` + its `company_document_id`), THEN drop the old FK/column and make the new columns required, all within one migration's `up()` since this table's row count is small (reminder logs, not documents themselves) and a multi-step migration adds risk without benefit here. Follow the exact polymorphic column pattern already used by `documents.owner_type`/`owner_id` (`database/migrations/2026_08_28_100010_create_documents_table.php`) for type/length consistency.

- [ ] **Step 5: Update the model and job**

`DocumentReminderLog::owner(): MorphTo` replacing whatever `belongsTo(CompanyDocument::class)` relation exists today. Update `SendDocumentExpiryReminderJob` to query both `CompanyDocument` rows (unchanged, still the vast majority of real data at this point) AND `Document` rows with a non-null `expires_at` (item 0.1's `Document` migration already has this column — confirm), writing reminder logs polymorphically for either.

- [ ] **Step 6: Run tests, full suite, Pint, commit**

```bash
php artisan migrate --force
php artisan test tests/Feature/DocumentReminderLogPolymorphicTest.php
php artisan test
vendor/bin/pint --dirty
git add database/migrations/*.php app/Models/DocumentReminderLog.php app/Jobs/SendDocumentExpiryReminderJob.php tests/Feature/DocumentReminderLogPolymorphicTest.php
git commit -m "Generalize DocumentReminderLog to a polymorphic owner, unblocking expiry reminders for Document-owned entities"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this, it has silently wiped the dev database before in this project.

---

### Task 2: Backfill command — copy `CompanyDocument`/`OrderDocument` rows into `documents`

**Files:**
- Create: `app/Console/Commands/BackfillDocumentsFromLegacyTables.php`
- Test: `tests/Feature/BackfillDocumentsFromLegacyTablesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Document;

it('backfills CompanyDocument rows into documents, preserving upload order for a correct hash chain', function () {
    $company = Company::factory()->create();
    $doc1 = CompanyDocument::factory()->create(['company_id' => $company->id, 'created_at' => now()->subDays(2)]);
    $doc2 = CompanyDocument::factory()->create(['company_id' => $company->id, 'created_at' => now()->subDay()]);
    $doc3 = CompanyDocument::factory()->create(['company_id' => $company->id, 'created_at' => now()]);

    $this->artisan('documents:backfill-from-legacy')->assertSuccessful();

    $chain = Document::where('owner_type', Company::class)->where('owner_id', $company->id)->orderBy('id')->get();
    expect($chain)->toHaveCount(3)
        ->and($chain[0]->prev_hash)->toBeNull()
        ->and($chain[1]->prev_hash)->toBe($chain[0]->hash)
        ->and($chain[2]->prev_hash)->toBe($chain[1]->hash);
});

it('is idempotent -- running it twice does not create duplicate Document rows', function () {
    $company = Company::factory()->create();
    CompanyDocument::factory()->create(['company_id' => $company->id]);

    $this->artisan('documents:backfill-from-legacy')->assertSuccessful();
    $firstCount = Document::count();
    $this->artisan('documents:backfill-from-legacy')->assertSuccessful();

    expect(Document::count())->toBe($firstCount);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/BackfillDocumentsFromLegacyTablesTest.php`
Expected: FAIL — command doesn't exist.

- [ ] **Step 3: Write the command**

Read `app/Models/CompanyDocument.php` and `app/Models/OrderDocument.php` in full first to map their real columns onto `Document`'s schema (`type`, `original_filename`, `disk`, `storage_path`, `mime_type`, `file_size`, `issuer`, `issued_at`, `expires_at`, `verification_status`) — do not guess field names. For idempotency, track backfilled rows via a marker (e.g. store the legacy table+id in a `legacy_source` JSON column on `Document`, or check `Document::where('owner_type', ...)->where('owner_id', ...)->where('original_filename', $legacy->original_filename)->where('created_at', $legacy->created_at)->exists()` before inserting — pick whichever is more robust given the real schema found). Process each owner's documents in `created_at` ASC, `id` ASC order (composite sort, since two uploads in the same second must still resolve deterministically) so `Document::booted()`'s hash chain builds correctly — do not batch-insert; create rows one at a time through Eloquent so the `creating` hook actually runs for each.

- [ ] **Step 4: Run tests, full suite, Pint, commit**

```bash
php artisan test tests/Feature/BackfillDocumentsFromLegacyTablesTest.php
php artisan test
vendor/bin/pint --dirty
git add app/Console/Commands/BackfillDocumentsFromLegacyTables.php tests/Feature/BackfillDocumentsFromLegacyTablesTest.php
git commit -m "Add documents:backfill-from-legacy: idempotent CompanyDocument/OrderDocument -> Document copy, upload-order-correct"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Migrate the `CompanyDocument` consumers, one at a time

**Files:** the ~24 files item 0.1's plan already enumerated (two Filament panels, 4 actions, 2 events, a notification, a policy, 3 services — re-confirm the exact list via `grep -rln "CompanyDocument\b" app` before starting).

- [ ] **Step 1: Run the backfill against dev data first**

```bash
php artisan documents:backfill-from-legacy
```

Confirm (via tinker or the command's own output) that every existing `CompanyDocument` row now has a matching `Document` row before touching any consumer — migrating a consumer to read from `Document` before the backfill has run would make it appear to have no documents.

- [ ] **Step 2: For each consumer file, in this order (dependency-safe: read-only consumers first, mutating consumers last)**

1. Both Filament panel resources/relation managers (read + upload UI)
2. The policy
3. The notification
4. The 2 events
5. The 4 actions
6. The 3 services (these are most likely to have the deepest logic — do these last, with the most care)

For each file:
- [ ] Read the file in full.
- [ ] Change every reference to the old `CompanyDocument`-specific relation/query to `$company->documents()` (or the equivalent `HasDocuments` trait method — `verifiedDocuments()` etc.), matching `Document`'s actual field names (`type`, `verification_status` as `DocumentVerificationStatus`, not the old table's possibly-differently-named columns — confirm by reading `app/Models/Document.php`).
- [ ] Run that file's own existing test (find it via `find tests -iname "*<RelevantName>*"`) and confirm it still passes with the new data source.
- [ ] Commit that one file's migration separately: `git commit -m "Migrate <FileName> from CompanyDocument to the polymorphic Document store"`.

- [ ] **Step 3: After every `CompanyDocument` consumer is migrated, run the full suite once**

```bash
php artisan test
```

Expected: green. If anything fails, it's almost certainly a field-name or verification-status-enum mismatch between the old and new schema — read the failure and fix the specific consumer, not the `Document` model (which item 0.8's certificate work already builds on top of and should not be altered here).

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 4: Migrate the `OrderDocument` consumers (4+), same discipline as Task 3

- [ ] Repeat Task 3's Step 2 process (read, migrate, test, commit per file) for each of the 4+ `OrderDocument` consumers, confirmed via `grep -rln "OrderDocument\b" app`.
- [ ] Full suite once at the end.

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 5: Empirical end-to-end verification

- [ ] **Step 1:** Via tinker, pick one real `Company` from dev data with multiple `CompanyDocument` rows. Confirm: (a) its documents are visible through BOTH the old `CompanyDocument` relation (still present, unchanged) AND the new `$company->documents()` relation (post-backfill), (b) the two return the same logical set of documents (same count, same original filenames), (c) uploading a NEW document through the migrated Filament form creates a `Document` row (not a `CompanyDocument` row) with a correct `prev_hash` continuing that company's existing chain from the backfilled history.
- [ ] **Step 2:** Report the actual observed values (counts, a real hash comparison), not just "it worked."

---

### Task 6: Record the deferred table-drop decision

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1:** Read the current Phase 0 table first (item numbers may have shifted) and mark `0.1b` done, adding a new `0.1c` row:

```markdown
| 0.1c | **Drop `company_documents`/`order_documents` and their models, once confirmed unused.** 0.1b migrated every known consumer to the polymorphic `Document` store but deliberately left the old tables/models in place and writable, as a rollback safety margin. Dropping them is a genuine breaking change (any code outside this repo's own consumer list — a report, an export script, a support query — could still reference them) and needs its own explicit sign-off, not a default follow-on to 0.1b. | Nothing blocks on this; it's cleanup, not a foundation. | 1 |
```

- [ ] **Step 2:** Commit.

```bash
git add docs/GAP_PLAN.md
git commit -m "Mark 0.1b done; track dropping the legacy document tables as 0.1c, pending explicit sign-off"
```

---

## Self-Review Notes

- **Spec coverage:** every consumer group from item 0.1's own original inventory is addressed (reminder log generalized first per its real blocking dependency, backfill built and proven order-correct, every Filament/action/event/notification/policy/service consumer migrated one file at a time with its own test, `OrderDocument`'s consumers likewise).
- **No silent data loss:** the backfill is idempotent and order-preserving; old tables are never dropped or write-blocked by this plan.
- **Verify-before-code discipline:** every task's first step is confirming real file/column names before writing code, matching the pattern that caught real wrong assumptions in every other large item this session.
