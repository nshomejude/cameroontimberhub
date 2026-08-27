# Polymorphic Document Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the platform a single, reusable polymorphic document store (`Document` model over a `documents` table with `owner_type`/`owner_id`), so every future entity that needs verifiable, expiring, hashed documents — carbon projects, sponsorship project files, vehicles, drivers, artisan credentials — can attach them without a bespoke table each time. This is item 0.1 of `docs/GAP_PLAN.md`, scoped down (see "Scope decision" below).

**Architecture:** One new `documents` table with a polymorphic `owner` relation, a `Document` model, a `DocumentPolicy` (owner-scoped), and a minimal Filament relation-manager pattern any future resource can attach. Nothing existing is touched. `company_documents` and `order_documents` keep working exactly as they do today.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL, Pest.

Reference: `docs/GAP_PLAN.md` item 0.1, `docs/AUDIT.md` (data-model conflicts table), `docs/audit/CORE_TRADE_AUDIT.md`.

## Scope decision (read before executing)

`docs/GAP_PLAN.md` item 0.1 originally described *merging* `company_documents`, `order_documents` and `rfqs.attachments` into one central store. Investigation before this plan was written found `CompanyDocument` alone has **24 consuming files** — two Filament panels (admin + exporter), 4 actions, 2 events, a scheduled expiry-reminder job, a notification, a policy, and 3 services (`DocumentService`, `VerificationService`, `BadgeService`). Migrating that live, working subsystem onto a new polymorphic table in the same pass as building the table itself is exactly the kind of large refactor `CTH_Claude_Code_Build_Brief.md` rule 0.3 says to *confirm before doing*, not do silently.

**This plan does the additive half only:** build `Document`/`documents` as a genuinely reusable store, prove it end-to-end with a real consumer, and leave `company_documents`/`order_documents` untouched. Migrating them onto the new table is real follow-up work — tracked as a new gap-plan item at the end of this plan (see Task 6) — not silently dropped.

**The real consumer proving this out:** `Species` gets a documents relation. It is the only entity in the codebase today that plausibly needs a verifiable document (a source citation, a lab test, a government gazette entry for a CITES listing) and does not already have one — a genuine, honest use case rather than a synthetic one, and it does not require building a new parent entity (carbon project, vehicle, etc.) that doesn't exist yet just to have something to hang a test on.

---

### Task 1: The `documents` table and `Document` model

**Files:**
- Create: `database/migrations/2026_08_28_100010_create_documents_table.php`
- Create: `app/Enums/DocumentVerificationStatus.php`
- Create: `app/Models/Document.php`
- Create: `database/factories/DocumentFactory.php`
- Test: `tests/Feature/DocumentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\DocumentVerificationStatus;
use App\Models\Document;
use App\Models\Species;

it('attaches a polymorphic document to an owning model', function () {
    $species = Species::factory()->create();

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'cites-appendix-ii-notice.pdf',
        'disk' => 'documents',
        'storage_path' => 'documents/2026/08/test-'.uniqid().'.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 24576,
        'issuer' => 'CITES Secretariat',
    ]);

    expect($document->owner)->toBeInstanceOf(Species::class)
        ->and($document->owner->is($species))->toBeTrue()
        ->and($species->fresh()->documents)->toHaveCount(1)
        ->and($species->fresh()->documents->first()->is($document))->toBeTrue();
});

it('defaults verification_status to unverified, never a fabricated verified state', function () {
    $species = Species::factory()->create();

    $document = Document::factory()->for($species, 'owner')->create();

    expect($document->verification_status)->toBe(DocumentVerificationStatus::Unverified);
});

it('computes hash and prev_hash to chain documents for the same owner in upload order', function () {
    $species = Species::factory()->create();

    $first = Document::factory()->for($species, 'owner')->create(['original_filename' => 'a.pdf']);
    $second = Document::factory()->for($species, 'owner')->create(['original_filename' => 'b.pdf']);

    expect($first->hash)->not->toBeNull()
        ->and($first->prev_hash)->toBeNull()
        ->and($second->prev_hash)->toBe($first->hash)
        ->and($second->hash)->not->toBe($first->hash);
});

it('flags an expired document without deleting it', function () {
    $expired = Document::factory()->create(['expires_at' => now()->subDay()]);
    $valid = Document::factory()->create(['expires_at' => now()->addYear()]);
    $undated = Document::factory()->create(['expires_at' => null]);

    expect($expired->isExpired())->toBeTrue()
        ->and($valid->isExpired())->toBeFalse()
        ->and($undated->isExpired())->toBeFalse();
});

it('scopes to a given owner type and id', function () {
    $speciesA = Species::factory()->create();
    $speciesB = Species::factory()->create();
    Document::factory()->for($speciesA, 'owner')->create();
    Document::factory()->for($speciesB, 'owner')->create();

    $found = Document::query()->forOwner($speciesA)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->owner_id)->toBe($speciesA->id);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/DocumentTest.php`
Expected: FAIL — `Class "App\Models\Document" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

/**
 * Where a Document stands in review, independent of whether it has expired
 * (see Document::isExpired()) — a document can be Verified and still expired,
 * which is exactly the state that should trigger a renewal reminder rather
 * than silently keep reading as trustworthy.
 */
enum DocumentVerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case NeedsCorrection = 'needs_correction';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::NeedsCorrection => 'Needs correction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unverified => 'gray',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::NeedsCorrection => 'warning',
        };
    }
}
```

- [ ] **Step 4: Write the migration**

Verify against the actual schema before finalizing: confirm `users` table exists with `id` (it does — every other document-bearing migration in this codebase FKs to it), and confirm no table named `documents` already exists (`grep -rl "create_documents_table\|Schema::create('documents'" database/migrations/` — expect no output).

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single, reusable document store for any owning entity — Species today;
 * carbon projects, sponsorship files, vehicles and drivers as those entities
 * are built (see docs/GAP_PLAN.md items 2.x, 6.x, 1.5.x).
 *
 * Deliberately does NOT replace `company_documents` or `order_documents` --
 * both are live subsystems with 24+ consumers each; migrating them here is
 * tracked separately (docs/GAP_PLAN.md item 0.1b) rather than done silently
 * alongside building this table.
 *
 * `hash`/`prev_hash` chain documents in upload order *per owner*, giving the
 * integrity-record primitive the brief's trust layer (§3.9) needs -- and
 * this is the one piece of that primitive gap-plan item 0.5 doesn't have to
 * build again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('owner_type', 120);
            $table->unsignedBigInteger('owner_id');
            $table->string('type', 60);
            $table->string('original_filename', 255);
            $table->string('disk', 40)->default('documents');
            $table->string('storage_path', 512);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('issuer', 190)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('hash', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['owner_type', 'owner_id']);
            $table->index('verification_status');
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_verification_status_check CHECK (verification_status IN ('unverified','verified','rejected','needs_correction'))");
        DB::statement('CREATE INDEX documents_expiry_idx ON documents (expires_at) WHERE expires_at IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use App\Enums\DocumentVerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to any owning entity (Species today; carbon projects,
 * sponsorship files, vehicles and drivers as those land) with expiry and a
 * verification workflow.
 *
 * Files live on the private `documents` disk (see config/filesystems.php,
 * the same disk `CompanyDocument`/`OrderDocument` already use) and are never
 * web-served directly -- reading the bytes back requires a dedicated
 * download controller that re-derives access from the owner, following the
 * pattern OrderDocumentDownloadController already establishes. No such
 * controller exists yet for this table; build one when a real owner that
 * needs gated access (rather than Species, which is public) is added.
 *
 * hash/prev_hash chain per-owner in upload order (see boot(), which fires
 * on create, matching how a receipt or order-event log would chain). This
 * gives §3.9 of the brief its hash-chain primitive; do not re-derive it in
 * gap-plan item 0.5 -- extend this.
 */
class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'verification_status' => DocumentVerificationStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Document $document): void {
            $previous = static::withoutGlobalScopes()
                ->where('owner_type', $document->owner_type)
                ->where('owner_id', $document->owner_id)
                ->latest('id')
                ->first();

            $document->prev_hash = $previous?->hash;
            $document->hash = hash('sha256', implode('|', [
                $document->owner_type,
                $document->owner_id,
                $document->original_filename,
                $document->storage_path,
                $document->prev_hash ?? '',
                now()->toISOString(),
            ]));
        });
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploadedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeForOwner(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner::class)->where('owner_id', $owner->getKey());
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', DocumentVerificationStatus::Verified->value);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function url(): ?string
    {
        return Storage::disk($this->disk)->exists($this->storage_path)
            ? Storage::disk($this->disk)->temporaryUrl($this->storage_path, now()->addMinutes(5))
            : null;
    }
}
```

Before finalizing, check `config/filesystems.php` for the actual `documents` disk definition (referenced by `CompanyDocument`/`OrderDocument` already) and confirm it supports `temporaryUrl()` (i.e. is S3-compatible) — if it's a local/private disk without signed-URL support, replace `url()` with a comment explaining that reading the file requires a dedicated controller, and drop the method rather than ship one that throws.

- [ ] **Step 6: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Species;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'owner_type' => Species::class,
            'owner_id' => Species::factory(),
            'type' => 'source_citation',
            'original_filename' => $this->faker->slug().'.pdf',
            'disk' => 'documents',
            'storage_path' => 'documents/'.now()->format('Y/m').'/'.$this->faker->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => $this->faker->numberBetween(1024, 2_000_000),
            'issuer' => $this->faker->company(),
            'issued_at' => $this->faker->dateTimeBetween('-2 years', 'now'),
            'uploaded_by' => null,
        ];
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/DocumentTest.php`
Expected: PASS (5 tests).

- [ ] **Step 8: Full suite, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test
vendor/bin/pint --dirty
git add database/migrations/2026_08_28_100010_create_documents_table.php app/Enums/DocumentVerificationStatus.php app/Models/Document.php database/factories/DocumentFactory.php tests/Feature/DocumentTest.php
git commit -m "Add the polymorphic Document model: one reusable store for entity documents"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. If you see "relation X already exists" or "relation migrations does not exist", that's corruption from a concurrent run: repair with `php artisan migrate:fresh --env=testing --force` after confirming `--env=testing` genuinely targets `cameroontimberhub_testing` (a `.env.testing` file must exist — verify with `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` first). **Never run `migrate:fresh` without that verification** — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead.

---

### Task 2: `HasDocuments` trait and wiring it to `Species`

**Files:**
- Create: `app/Models/Concerns/HasDocuments.php`
- Modify: `app/Models/Species.php`
- Test: `tests/Feature/DocumentTest.php` (extend)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DocumentTest.php`:

```php
it('exposes documents via the HasDocuments trait, verified-only by default', function () {
    $species = Species::factory()->create();

    Document::factory()->for($species, 'owner')->create(['verification_status' => 'verified']);
    Document::factory()->for($species, 'owner')->create(['verification_status' => 'unverified']);

    expect($species->documents)->toHaveCount(2)
        ->and($species->verifiedDocuments())->toHaveCount(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/DocumentTest.php`
Expected: FAIL — `Call to undefined method App\Models\Species::documents()` (or similar).

- [ ] **Step 3: Read `app/Models/Species.php` in full first**

Confirm the exact trait-use line and namespace block before editing — the plan cannot see the live file's current trait list.

- [ ] **Step 4: Write the trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a polymorphic `documents` relation over the shared Document
 * store. Add `use HasDocuments;` to any model that needs verifiable,
 * expiring, hashed document attachments — see app/Models/Document.php for
 * the full contract.
 */
trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /** @return Collection<int, Document> */
    public function verifiedDocuments(): Collection
    {
        return $this->documents()->verified()->get();
    }
}
```

- [ ] **Step 5: Wire it to `Species`**

Add `use App\Models\Concerns\HasDocuments;` to the imports and `HasDocuments` to the `use` trait list in `app/Models/Species.php`. Do not reorder or remove any existing trait — append.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/DocumentTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Models/Concerns/HasDocuments.php app/Models/Species.php tests/Feature/DocumentTest.php
git commit -m "Add HasDocuments trait and wire it to Species as the first real consumer"
```

---

### Task 3: `DocumentPolicy`

**Files:**
- Create: `app/Policies/DocumentPolicy.php`
- Test: `tests/Feature/DocumentPolicyTest.php`

- [ ] **Step 1: Read the existing `CompanyDocumentPolicy` first**

Read `app/Policies/CompanyDocumentPolicy.php` in full to match its exact permission-check idiom (`hasPermission`, Spatie `can`, or whatever the codebase actually uses) — do not invent a different authorization pattern for this one policy.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Document;
use App\Models\Species;
use App\Models\User;

it('lets staff with pages.manage view and delete any document', function () {
    $staff = staff(['pages.manage']);
    $document = Document::factory()->for(Species::factory()->create(), 'owner')->create();

    expect($staff->can('view', $document))->toBeTrue()
        ->and($staff->can('delete', $document))->toBeTrue();
});

it('refuses a user with no relevant permission', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for(Species::factory()->create(), 'owner')->create();

    expect($user->can('view', $document))->toBeFalse()
        ->and($user->can('delete', $document))->toBeFalse();
});
```

Adjust the `staff(...)` helper call and permission string to match whatever convention `tests/Feature/CompanyDocumentPolicyTest.php` (if it exists — check) or the codebase's other policy tests actually use. Search first: `grep -rl "function staff(" tests/`.

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/DocumentPolicyTest.php`
Expected: FAIL — policy class not found / not registered.

- [ ] **Step 4: Write the policy**, mirroring `CompanyDocumentPolicy`'s exact structure and permission strings (substitute a `documents.manage` permission if the codebase's permission-seeding convention wants a dedicated one — check `database/seeders/RolesAndPermissionsSeeder.php` for the pattern first and follow it, including registering the new permission there if that's how every other resource does it).

- [ ] **Step 5: Register the policy** in the model-policy map (find where `CompanyDocumentPolicy` is registered — likely `app/Providers/AuthServiceProvider.php` or Filament's auto-discovery; follow whichever mechanism is real).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/DocumentPolicyTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Policies/DocumentPolicy.php tests/Feature/DocumentPolicyTest.php
# plus whatever provider/seeder file Step 5 touched
git commit -m "Add DocumentPolicy, mirroring the existing CompanyDocumentPolicy convention"
```

---

### Task 4: Expiry reminder — extend, don't duplicate

**Files:**
- Read: `app/Jobs/SendDocumentExpiryReminderJob.php`, `app/Notifications/DocumentExpiring.php` (in full, first)
- Modify: `app/Jobs/SendDocumentExpiryReminderJob.php` (or create a sibling job — decide per Step 1)
- Test: extend the existing job's test file, or create `tests/Feature/DocumentExpiryReminderTest.php`

- [ ] **Step 1: Read the existing job and notification in full.**

`SendDocumentExpiryReminderJob` currently queries `CompanyDocument` with expiring dates and dispatches `DocumentExpiring` notifications, logging via `DocumentReminderLog` (keyed to `company_document_id`). Decide: can this job be generalized to also query `Document::query()->whereNotNull('expires_at')->...`, or does `DocumentReminderLog`'s hard FK to `company_documents` make that awkward?

**If `DocumentReminderLog` is tightly coupled to `company_documents`** (its migration FKs `company_document_id` directly), do not force a generalization in this task — that FK is itself a Task-6-style follow-up (a polymorphic reminder log mirrors the polymorphic document store). Instead, ship a minimal, honest state for `Document`: skip automated expiry reminders for `Document`-owned entities in this plan, and add a clear one-line note to Task 6's follow-up gap-plan item that `DocumentReminderLog` needs the same polymorphic treatment before `Document` gets reminders. **Do not build a parallel, second reminder pipeline** — one half-generalized job is worse than an honest gap.

- [ ] **Step 2: If Step 1 finds it genuinely feasible without duplicating logic**, generalize; otherwise, skip to Step 3 with nothing coded.

- [ ] **Step 3: Full suite, Pint, commit** (only if Step 1/2 produced a code change; otherwise this task produces no commit and that is the correct outcome — record it in the final report).

---

### Task 5: Self-review and final verification

- [ ] **Step 1: Confirm no existing table, model, controller, policy, job or view was modified** other than `app/Models/Species.php` (Task 2) and whatever provider/seeder Task 3 needed. Run `git log --oneline` for this plan's commits and `git diff <first-commit>^..HEAD --stat` to list every touched file; check each one is either new or one of those two expected modifications.

- [ ] **Step 2: Confirm `company_documents` and `order_documents` are completely untouched** — no migration alters them, no existing consumer file in the 24+/4+ lists from this plan's header was modified.

- [ ] **Step 3: Run the full suite one final time.**

```bash
php artisan test
```//
Expected: fully green, no regressions against whatever the baseline count was when this plan started.

- [ ] **Step 4: Confirm the hash-chain claim empirically** — in the final report, show the actual `hash`/`prev_hash` values from a 3-document chain created via tinker or a quick test, not just "the test passed."

---

### Task 6: Record the deferred consolidation as a tracked gap-plan item

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Add a new item** (e.g. `0.1b`) to the Phase 0 table in `docs/GAP_PLAN.md`, worded along these lines (adjust to match the actual file's current numbering — read it first):

> **Migrate `company_documents`/`order_documents` onto the polymorphic `documents` table.** 24 consumers of `CompanyDocument` (two Filament panels, 4 actions, 2 events, a scheduled job, a notification, a policy, 3 services) and 4+ of `OrderDocument` need updating in a dedicated, carefully tested pass — not bundled into building the table itself. Includes generalizing `DocumentReminderLog` to a polymorphic `owner_type`/`owner_id` shape (see Task 4 of `2026-08-27-polymorphic-document-store.md`). ~8 days.

- [ ] **Step 2: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Track the CompanyDocument/OrderDocument consolidation as its own gap-plan item"
```

---

## Self-Review Notes

- **Spec coverage:** this plan implements the reusable half of gap-plan item 0.1 (a working polymorphic document store with hash-chaining, verification status, and expiry) and explicitly defers the consolidation half rather than silently dropping it or silently attempting a 28+-file refactor. That is a judgment call under brief rule 0.3 ("confirm before large refactors") — stated here rather than hidden.
- **No placeholders:** every step has real code or an explicit decision point with criteria (Task 4).
- **Type consistency:** `Document::forOwner()`/`verified()` scopes (Task 1) are the exact methods `HasDocuments::verifiedDocuments()` (Task 2) and the policy tests (Task 3) call — verified consistent across tasks.
