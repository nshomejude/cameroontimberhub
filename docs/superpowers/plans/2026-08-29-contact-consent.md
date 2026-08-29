# Contact-Form Consent Implementation Plan (0.4b-ii)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the last consent gap (0.4b-ii) — `ContactController::store()` validates a `consent` checkbox and discards it, exactly like the RFQ wizard and inquiry form did before items 0.4/0.4b fixed them.

**Architecture, and the schema decision made here:** `ContactController::store()` today persists nothing — it only sends `ContactMessageMail`. Two options existed (see `docs/superpowers/plans/2026-08-28-inquiry-consent.md`'s Scope Decision, which deferred this exact choice): (a) make `consents.subject_id` nullable, or (b) persist a `ContactMessage` model. **Decision: (b).** Every other public intake form on this platform (RFQ, inquiry) already persists its submission as a real row — a contact message being the one exception (email-only, no record) is the actual inconsistency, not the thing to work around. Adding a `ContactMessage` model is a small, additive, honestly-scoped change that also gives the contact form the same audit trail every other intake flow has, rather than special-casing the shared `consents` table's NOT NULL constraint for one caller.

**Tech Stack:** Laravel 13, Pest.

Reference: `app/Http/Controllers/Public/ContactController.php`, `app/Services/IntakeService.php`, `app/Models/CompanyInquiry.php` (the pattern to mirror), `app/Enums/ConsentPurpose.php`, `docs/superpowers/plans/2026-08-28-inquiry-consent.md`.

**Module boundary (do not touch anything outside this list):** new `ContactMessage` model/migration/factory, `ContactController.php`, `IntakeService.php` (only the new `createContactMessage()` method — do not touch `createRfq()`/`createInquiry()`), `ConsentPurpose.php` (add one case), one new consents-CHECK-extension migration, new tests. Do NOT touch `CompanyInquiry.php`, `InquiryController.php`, `Rfq`-related files, or anything under `app/Console/Commands/`, `app/Models/Document*`, `app/Models/ChainedActivity.php`, `app/Models/Certificate*` — those belong to other concurrently-dispatched agents' modules.

---

### Task 1: `ContactMessage` model + `ContactMessageSharing` consent purpose

**Files:**
- Create: `database/migrations/2026_08_29_100040_create_contact_messages_table.php`
- Create: `app/Models/ContactMessage.php`
- Create: `database/factories/ContactMessageFactory.php`
- Modify: `app/Enums/ConsentPurpose.php` (add `ContactMessageSharing` case)
- Create: `database/migrations/2026_08_29_100050_add_contact_message_sharing_to_consents_check.php` (additive CHECK extension — do NOT edit the original `2026_08_27_100010_create_consents_table.php` or the `2026_08_28_100030_...` migration item 0.4b already added)
- Test: `tests/Feature/ContactMessageConsentTest.php`

- [ ] **Step 1: Verify against reality first**

Read `app/Http/Controllers/Public/ContactController.php::store()` and `app/Models/CompanyInquiry.php` in full (confirm the exact validated field list: `name`, `company`, `email`, `phone`, `subject`, `message`, `consent`, plus the honeypot fields `website`/`form_rendered_at` which must NOT be persisted).

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\ConsentPurpose;
use App\Models\ContactMessage;

it('has a ContactMessageSharing consent purpose accepted by the database CHECK constraint', function () {
    $message = ContactMessage::factory()->create();

    $consent = $message->consents()->create([
        'purpose' => ConsentPurpose::ContactMessageSharing,
        'granted_at' => now(),
    ]);

    expect($consent->fresh())->not->toBeNull();
});

it('gives ContactMessage the HasConsents trait', function () {
    $message = ContactMessage::factory()->create();

    expect($message->hasActiveConsent(ConsentPurpose::ContactMessageSharing))->toBeFalse();

    $message->consents()->create(['purpose' => ConsentPurpose::ContactMessageSharing, 'granted_at' => now()]);

    expect($message->fresh()->hasActiveConsent(ConsentPurpose::ContactMessageSharing))->toBeTrue();
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/ContactMessageConsentTest.php`
Expected: FAIL — `Class "App\Models\ContactMessage" not found`.

- [ ] **Step 4: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists contact-form submissions (gap-plan 0.4b-ii). Previously this
 * form only sent an email (ContactMessageMail) with no persisted record --
 * the one public intake flow with no audit trail, unlike Rfq/CompanyInquiry.
 * Gives it a real subject to attach a Consent row to instead of requiring
 * consents.subject_id to become nullable for one caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 120);
            $table->string('company', 160)->nullable();
            $table->string('email', 180);
            $table->string('phone', 40)->nullable();
            $table->string('subject', 200);
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasConsents;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use HasConsents, HasFactory;

    protected $guarded = ['id'];
}
```

- [ ] **Step 6: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContactMessage> */
class ContactMessageFactory extends Factory
{
    protected $model = ContactMessage::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'company' => $this->faker->company(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'subject' => $this->faker->sentence(4),
            'message' => $this->faker->paragraph(),
            'ip_address' => $this->faker->ipv4(),
        ];
    }
}
```

- [ ] **Step 7: Extend `ConsentPurpose`**

In `app/Enums/ConsentPurpose.php`, add:

```php
    case ContactMessageSharing = 'contact_message_sharing';
```

and in `label()`:

```php
            self::ContactMessageSharing => 'Contact message shared with the team',
```

Update the class docblock's case count/description.

- [ ] **Step 8: Additive CHECK-constraint extension**

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing','company_inquiry_sharing','contact_message_sharing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE consents DROP CONSTRAINT consents_purpose_check');
        DB::statement("ALTER TABLE consents ADD CONSTRAINT consents_purpose_check CHECK (purpose IN ('rfq_exporter_sharing','company_inquiry_sharing'))");
    }
};
```

Verify first via `php artisan migrate:status` that both prior consents-CHECK migrations have already run — if the CHECK constraint's current value list differs from what this Step assumes (e.g. `company_inquiry_sharing` isn't there yet because that migration hasn't run in this environment), adjust the `up()`/`down()` value lists to match reality, don't guess.

- [ ] **Step 9: Run tests, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test tests/Feature/ContactMessageConsentTest.php
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100040_create_contact_messages_table.php \
        database/migrations/2026_08_29_100050_add_contact_message_sharing_to_consents_check.php \
        app/Models/ContactMessage.php database/factories/ContactMessageFactory.php \
        app/Enums/ConsentPurpose.php tests/Feature/ContactMessageConsentTest.php
git commit -m "Add ContactMessage model and ContactMessageSharing consent purpose"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this.

---

### Task 2: Wire the controller to persist the message + consent

**Files:**
- Modify: `app/Services/IntakeService.php` (add `createContactMessage()` — do not touch any other method)
- Modify: `app/Http/Controllers/Public/ContactController.php` (`store()`)
- Test: `tests/Feature/ContactTest.php` — find the real existing test file via `find tests -iname "*Contact*"` first and extend it if one exists; create one matching this codebase's real conventions otherwise.

- [ ] **Step 1: Write the failing test**

```php
it('persists a contact message and a Consent record when the checkbox is checked', function () {
    $this->post(route('contact.store'), [
        'name' => 'Jane Buyer',
        'email' => 'jane@example.com',
        'subject' => 'Sourcing question',
        'message' => str_repeat('Interested in your sapelli stock. ', 3),
        'consent' => '1',
    ])->assertRedirect();

    $message = \App\Models\ContactMessage::where('email', 'jane@example.com')->firstOrFail();
    expect($message->consents()->where('purpose', \App\Enums\ConsentPurpose::ContactMessageSharing->value)->exists())->toBeTrue();
});

it('persists a contact message with no consent record when the checkbox is unchecked', function () {
    // Confirm first whether ContactController's real validation makes
    // `consent` required ('accepted') or optional -- if required, this
    // test may not be reachable; adjust or drop it based on what Step 1
    // of Task 1/2 actually found in the controller.
});
```

- [ ] **Step 2: Add `createContactMessage()` to `IntakeService`**

```php
    public function createContactMessage(array $data, bool $consentGiven = false): ContactMessage
    {
        $message = ContactMessage::create(array_merge($data, [
            'ip_address' => request()->ip(),
        ]));

        if ($consentGiven) {
            $message->consents()->create([
                'purpose' => ConsentPurpose::ContactMessageSharing,
                'granted_at' => now(),
                'evidence' => [
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ],
            ]);
        }

        return $message;
    }
```

Add `use App\Models\ContactMessage;` to the file's imports.

- [ ] **Step 3: Wire the controller**

In `app/Http/Controllers/Public/ContactController.php::store()`, after the existing validation and before/alongside the `Mail::to(...)->send(...)` call, add:

```php
        $intake->createContactMessage([
            'name' => trim($data['name']),
            'company' => $data['company'] ?? null,
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
        ], filled($data['consent'] ?? null));
```

Add `IntakeService $intake` to the method's dependency-injected parameters if not already present (it likely already is — `store()` was shown during planning without it, confirm by re-reading the current file).

- [ ] **Step 4: Run tests, full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/IntakeService.php app/Http/Controllers/Public/ContactController.php tests/
git commit -m "Persist the contact form's consent checkbox and message, closing the last consent gap (0.4b-ii)"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Record completion

**Files:** `docs/GAP_PLAN.md`

- [ ] Re-read the file fresh (other agents may be editing it concurrently — treat this file as contested; re-read immediately before editing, and if another agent's edit already landed near your target row, adjust your insertion rather than overwriting).
- [ ] Mark `0.4b-ii` done with a one-line summary and commit SHAs.
- [ ] `git commit -m "Mark contact-form consent (0.4b-ii) done"`.

---

## Self-Review Notes

- **Module boundary respected:** only `ContactMessage`-related and `ContactController`/`IntakeService::createContactMessage()` files touched; no other agent's module (documents, certificates, activity log) is referenced or modified.
- **Consistent with the established pattern:** mirrors `createInquiry()`'s exact `$consentGiven` parameter shape from item 0.4b, not a new convention.
