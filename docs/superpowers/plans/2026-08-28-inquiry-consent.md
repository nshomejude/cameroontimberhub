# Inquiry Consent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build gap-plan item 0.4b's inquiry half — wire `InquiryController::store()`'s already-validated-but-discarded `consent` checkbox onto the `Consent` ledger (built in item 0.4), exactly the same fix item 0.4 already made for the RFQ wizard.

**Architecture:** Add a `CompanyInquirySharing` case to the existing `App\Enums\ConsentPurpose` enum (and its matching DB CHECK constraint — additive, does not touch the existing `rfq_exporter_sharing` case), wire the existing `HasConsents` trait (built in item 0.4, currently only used by `Rfq`) onto `CompanyInquiry`, and persist a `Consent` row from `IntakeService::createInquiry()` exactly where the checkbox is currently validated then dropped.

**Tech Stack:** Laravel 13, Pest.

Reference: `docs/superpowers/plans/2026-08-27-persisted-consent.md` (the original item 0.4 plan — this plan is the same pattern applied to a second consumer), `app/Models/Concerns/HasConsents.php`, `app/Enums/ConsentPurpose.php`, `app/Http/Controllers/Public/InquiryController.php`, `app/Services/IntakeService.php`, `app/Models/CompanyInquiry.php`, `database/migrations/2026_08_27_100010_create_consents_table.php`.

## Scope decision (read before executing)

**What already exists, confirmed by investigation:** `InquiryController::store()` validates `'consent' => ['accepted']` and never uses the value again — `IntakeService::createInquiry(Company $company, array $data)` is called with only `name`/`email`/`phone`/`message`, the checkbox result is silently dropped. `App\Models\Concerns\HasConsents` (built in item 0.4) already provides `consents()`/`activeConsents()`/`hasActiveConsent()` and is proven working on `Rfq` — this plan adds it to `CompanyInquiry` as a second, independent consumer, following the trait's own intended reuse (its docblock explicitly anticipates this). `ConsentPurpose`'s own docblock explicitly names "the contact/inquiry forms' own consent checkboxes" as the next expected case to add.

**Scoped to the inquiry form only — the contact form is a real, separate follow-up, not silently dropped.** `ContactController::store()` also validates a `consent` checkbox and also discards it, but `consents.subject_type`/`subject_id` are `NOT NULL` in the schema, and the contact form has no persisted entity today (it only sends an email via `ContactMessageMail` — no `ContactMessage` model, no row, nothing to attach a polymorphic `Consent` to). Wiring it would require either (a) making `consents.subject_id` nullable — a real, deliberate schema decision affecting the existing partial unique/active indexes, not a drop-in fix, or (b) creating a new persisted `ContactMessage` model purely to hang a consent record off it, which would be scope creep into building storage the contact flow doesn't otherwise need. Neither is a small addition to this plan; both are tracked as the renamed follow-up **0.4b-ii** in Task 3 below, with the actual decision left to a human rather than picked here.

---

### Task 1: `CompanyInquirySharing` consent purpose + `HasConsents` on `CompanyInquiry`

**Files:**
- Modify: `app/Enums/ConsentPurpose.php`
- Modify: `database/migrations/2026_08_27_100010_create_consents_table.php` — **do NOT edit an already-migrated file's `up()` in place on a shared environment.** Confirm first via `php artisan migrate:status` (or `grep` the migrations table) whether `2026_08_27_100010_create_consents_table` has already run on the dev database. If it has (expected, since item 0.4 shipped and deployed this migration already), create a NEW additive migration instead — do not edit the old file's CHECK constraint.
- Create (if the above confirms the old migration already ran): `database/migrations/2026_08_28_100030_add_company_inquiry_sharing_to_consents_check.php`
- Modify: `app/Models/CompanyInquiry.php`
- Test: `tests/Feature/CompanyInquiryConsentTest.php`

- [ ] **Step 1: Verify against reality first**

```bash
php artisan migrate:status | grep consents
```

If it shows `Ran`, proceed with the new-migration approach in Step 3 below. If for some reason it shows not-yet-run in this exact environment (unlikely — item 0.4 is deployed), you may edit the original migration's CHECK constraint directly instead and skip Step 3's separate file — note which path you took in your final report.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\ConsentPurpose;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Consent;

it('has a CompanyInquirySharing consent purpose accepted by the database CHECK constraint', function () {
    $inquiry = CompanyInquiry::factory()->for(Company::factory())->create();

    $consent = Consent::create([
        'subject_type' => CompanyInquiry::class,
        'subject_id' => $inquiry->id,
        'purpose' => ConsentPurpose::CompanyInquirySharing->value,
        'granted_at' => now(),
    ]);

    expect($consent->fresh())->not->toBeNull()
        ->and($consent->purpose)->toBe(ConsentPurpose::CompanyInquirySharing);
});

it('gives CompanyInquiry the HasConsents trait', function () {
    $inquiry = CompanyInquiry::factory()->for(Company::factory())->create();

    expect($inquiry->consents())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class)
        ->and($inquiry->hasActiveConsent(ConsentPurpose::CompanyInquirySharing))->toBeFalse();

    Consent::create([
        'subject_type' => CompanyInquiry::class,
        'subject_id' => $inquiry->id,
        'purpose' => ConsentPurpose::CompanyInquirySharing->value,
        'granted_at' => now(),
    ]);

    expect($inquiry->fresh()->hasActiveConsent(ConsentPurpose::CompanyInquirySharing))->toBeTrue();
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/CompanyInquiryConsentTest.php`
Expected: FAIL — `Undefined constant App\Enums\ConsentPurpose::CompanyInquirySharing` (or the CHECK constraint rejects the insert, depending on which fails first).

- [ ] **Step 4: Extend the enum**

In `app/Enums/ConsentPurpose.php`:

```php
enum ConsentPurpose: string
{
    case RfqExporterSharing = 'rfq_exporter_sharing';
    case CompanyInquirySharing = 'company_inquiry_sharing';

    public function label(): string
    {
        return match ($this) {
            self::RfqExporterSharing => 'RFQ shared with verified exporters',
            self::CompanyInquirySharing => 'Inquiry shared with the company',
        };
    }
}
```

Update the class docblock's "Only one case exists today" sentence to reflect two cases now exist, and that the contact form's own case is the next expected addition once its subject-storage question (see this plan's Scope Decision) is resolved.

- [ ] **Step 5: Add the new CHECK value additively** (only if Step 1 confirmed the original migration already ran)

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Additive: extends consents_purpose_check to accept 'company_inquiry_sharing'
 * (App\Enums\ConsentPurpose::CompanyInquirySharing) alongside the existing
 * 'rfq_exporter_sharing' value. Does not touch any existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing','company_inquiry_sharing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing'))");
    }
};
```

- [ ] **Step 6: Wire `HasConsents` onto `CompanyInquiry`**

In `app/Models/CompanyInquiry.php`, add `use App\Models\Concerns\HasConsents;` to the imports and `HasConsents` to the model's `use` trait clause (alongside the existing `HasFactory`). Do not remove or modify any other existing method on the class.

- [ ] **Step 7: Run tests, migrate, verify**

```bash
php artisan migrate --force
php artisan test tests/Feature/CompanyInquiryConsentTest.php
```

Expected: PASS (2 tests).

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this, it has silently wiped the dev database before in this project.

- [ ] **Step 8: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Enums/ConsentPurpose.php app/Models/CompanyInquiry.php \
        database/migrations/2026_08_28_100030_add_company_inquiry_sharing_to_consents_check.php \
        tests/Feature/CompanyInquiryConsentTest.php
git commit -m "Add CompanyInquirySharing consent purpose and wire HasConsents onto CompanyInquiry"
```

(Omit the migration file from `git add` if Step 1 determined the original migration hadn't run yet and you edited it directly instead.)

---

### Task 2: Persist the inquiry form's consent checkbox instead of discarding it

**Files:**
- Modify: `app/Services/IntakeService.php` (`createInquiry()`)
- Modify: `app/Http/Controllers/Public/InquiryController.php` (`store()`)
- Test: `tests/Feature/InquiryTest.php` — read this file first to find its actual existing conventions/helpers before adding to it; if no such file exists, check for the actual current inquiry test file name via `find tests -iname "*inquiry*"`.

- [ ] **Step 1: Verify against reality first**

Read `app/Services/IntakeService.php`'s `createInquiry()` method and `app/Http/Controllers/Public/InquiryController.php`'s `store()` method in full (already read once during planning — confirm they still match: `createInquiry(Company $company, array $data): CompanyInquiry`, called from the controller with `name`/`email`/`phone`/`message` only, `consent` validated via `'consent' => ['accepted']` then dropped). Find and read the existing inquiry test file to match its real fixture/helper conventions exactly.

- [ ] **Step 2: Write the failing test**

Add to the existing inquiry test file (match its real `use` statements/helpers — this is illustrative of the required assertions, not a literal drop-in if the real file's conventions differ):

```php
it('persists a Consent record from the inquiry form checkbox instead of discarding it', function () {
    $company = Company::factory()->create(['status' => \App\Enums\CompanyStatus::Verified]);

    $this->post(route('inquiry.store', $company), [
        'name' => 'Jane Buyer',
        'email' => 'jane@example.com',
        'message' => str_repeat('Interested in your sapelli stock. ', 3),
        'consent' => '1',
    ])->assertRedirect();

    $inquiry = \App\Models\CompanyInquiry::where('email', 'jane@example.com')->firstOrFail();

    expect($inquiry->consents()->where('purpose', \App\Enums\ConsentPurpose::CompanyInquirySharing->value)->exists())->toBeTrue();
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test` on the real inquiry test file's path.
Expected: FAIL — no `Consent` row exists for the created inquiry.

- [ ] **Step 4: Wire it through**

In `app/Services/IntakeService.php`, change `createInquiry()`'s signature to accept a trailing `bool $consentGiven = false` (additive — matches exactly how item 0.4's `createRfq()` added its own `$consentGiven` parameter, so any other call site keeps compiling and correctly records no consent):

```php
    public function createInquiry(Company $company, array $data, bool $consentGiven = false): CompanyInquiry
    {
        $inquiry = $company->inquiries()->create(array_merge($data, [
            'status' => 'new',
            'ip_address' => request()->ip(),
        ]));

        if ($consentGiven) {
            $inquiry->consents()->create([
                'purpose' => ConsentPurpose::CompanyInquirySharing,
                'granted_at' => now(),
                'scope' => ['shared_with' => 'company'],
                'evidence' => [
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ],
            ]);
        }

        Mail::to($inquiry->email)->send(new InquiryVerificationMail($inquiry, $this->inquiryVerifyUrl($inquiry)));

        return $inquiry;
    }
```

Add `use App\Enums\ConsentPurpose;` to the file's imports if not already present (it likely is, from item 0.4's RFQ work in this same file — check before adding a duplicate).

In `app/Http/Controllers/Public/InquiryController.php`, change the `createInquiry` call:

```php
        $intake->createInquiry($company, [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'],
        ], filled($data['consent'] ?? null));
```

- [ ] **Step 5: Run tests to verify they pass**

Run the real inquiry test file's full suite.
Expected: PASS, including the new test and every pre-existing test in that file unchanged.

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/IntakeService.php app/Http/Controllers/Public/InquiryController.php
git add tests/ # whichever real inquiry test file was modified
git commit -m "Persist the inquiry form's consent checkbox as a Consent record instead of discarding it"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Record the contact-form follow-up and self-review

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Read the current end of the Phase 0 table** (item numbers may have shifted since this plan was written) and rename/replace the existing `0.4b` row — it currently covers both inquiry and contact; split it so the inquiry half is marked done and the contact half survives as its own row:

```markdown
| 0.4b | ✅ **Inquiry-form consent — done.** `CompanyInquirySharing` consent purpose added to the existing `Consent` ledger (additive CHECK-constraint extension, does not touch `rfq_exporter_sharing`), `HasConsents` wired onto `CompanyInquiry`, and `IntakeService::createInquiry()` now persists a `Consent` row from the inquiry form's checkbox instead of discarding it (`InquiryController::store()` forwards the checkbox value through, matching exactly how item 0.4 did this for the RFQ wizard). Plan: `docs/superpowers/plans/2026-08-28-inquiry-consent.md`. | — | 0 (done) |
| 0.4b-ii | **Contact-form consent — blocked on a schema decision.** `ContactController::store()` still validates and discards its own `consent` checkbox. Unlike the RFQ/inquiry flows, the contact form has no persisted entity to attach a `Consent` row to (`consents.subject_type`/`subject_id` are `NOT NULL`) — it only sends an email via `ContactMessageMail`. Needs a human decision: either make `consents.subject_id` nullable (affects the existing partial unique/active indexes — a real schema change, not a drop-in fix) or persist a `ContactMessage` model purely to hang consent off it (new storage the contact flow doesn't otherwise need). Neither was picked here — see `docs/superpowers/plans/2026-08-28-inquiry-consent.md`'s "Scope decision". | Same GDPR-style consent gap as the RFQ/inquiry forms, on the one remaining flow. | 1–2 depending on which option is chosen |
```

- [ ] **Step 2: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Mark inquiry-form consent (0.4b) done; split the contact-form half out as 0.4b-ii, blocked on a schema decision"
```

No test run needed — documentation only.

---

## Self-Review Notes

- **Spec coverage:** Task 1 covers the new consent purpose + trait wiring; Task 2 covers actually persisting it from the real form submission (mirroring item 0.4's own `$consentGiven` parameter pattern exactly, for consistency an implementer or reviewer familiar with item 0.4 will recognize immediately); Task 3 honestly separates the inquiry half (done) from the contact half (genuinely blocked on a decision, not silently dropped).
- **No placeholders:** every step has complete, runnable code.
- **Type consistency:** `ConsentPurpose::CompanyInquirySharing` (Task 1) is the exact value `IntakeService::createInquiry()` (Task 2) writes and the test (Task 1 and Task 2) both assert against. `createInquiry()`'s new trailing `bool $consentGiven = false` parameter matches `createRfq()`'s existing pattern from item 0.4 exactly, so this plan's Task 2 doesn't invent a different convention for the same problem.
