# Document Consumer Migration v2 (Gap Plan 0.1b, corrected) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve the relation-name collision between `Company::documents()` / `Order::documents()` (legacy `HasMany` to `CompanyDocument`/`OrderDocument`) and `HasDocuments::documents()` (polymorphic `MorphMany` to `Document`) so both models can genuinely hold polymorphic documents going forward, without touching any of the 42 existing files that depend on `CompanyDocument`/`OrderDocument` behaving exactly as they do today.

**Architecture:** Rename the two legacy relations to `companyDocuments()` / `orderDocuments()`, update their small, real set of call sites, then add `HasDocuments` to `Company` and `Order` so `documents()` becomes the polymorphic relation on both models. `DocumentAccessLog`, `VerificationBadge.supporting_document_id`, and `VerificationRequest.document_snapshot` are deliberately **not** made polymorphic in this pass — they stay hard-tied to `CompanyDocument` permanently (see Scope Decision). This is a partial, additive migration: legacy tables, Filament resources, policies, download controllers, and jobs keep working unmodified; only the relation name and the newly-available polymorphic slot change.

**Tech Stack:** Laravel 13, PHPUnit/Pest (`tests/Feature`), existing `Document`/`HasDocuments` polymorphic store from gap-plan 0.1.

---

## Scope Decision

### What the previous attempt found (and why it correctly blocked)

- `Company::documents(): HasMany` at `app/Models/Company.php:174-177` returns `CompanyDocument` rows.
- `Order::documents(): HasMany` at `app/Models/Order.php:98-101` returns `OrderDocument` rows.
- `HasDocuments::documents(): MorphMany` at `app/Models/Concerns/HasDocuments.php:18-21` returns polymorphic `Document` rows.
- Adding `use HasDocuments;` to `Company` or `Order` as-is would silently override (or fatal-collide with) the existing `documents()` method — the exact reason gap-plan item 0.1b's first dispatch stopped rather than migrate consumers.
- Additionally: `DocumentAccessLog::document()` (`app/Models/DocumentAccessLog.php:20-23`) is a hard `belongsTo(CompanyDocument::class, 'company_document_id')`; `VerificationBadge::supportingDocument()` (`app/Models/VerificationBadge.php:50-53`) is a hard `belongsTo(CompanyDocument::class, 'supporting_document_id')`; `VerificationRequest.document_snapshot` (`app/Services/VerificationService.php:172-181`) is a cast `array` column that stores `{id, document_type_id, status}` triples read back from `CompanyDocument` rows via `Company::documents()->get(...)`. None of these were in the original plan's consumer inventory.

### Real inventory of `Company::documents()` / `Order::documents()` call sites (this investigation)

Only **5 real call sites** touch the two legacy relation names directly (excluding `HasDocuments`'s own unrelated `documents()` definition and the unrelated `Order::documents()` self-reference used by `OrderDocumentService`):

| # | File:line | Usage |
|---|---|---|
| 1 | `app/Models/Company.php:466` (`publicDocuments()`) | `$this->documents()->where('visibility', ...)` |
| 2 | `app/Services/DocumentService.php:30` | `$company->documents()->create(...)` |
| 3 | `app/Services/BadgeService.php:103` (`approvedDocument()`) | `$company->documents()->whereHas('documentType', ...)` |
| 4 | `app/Services/VerificationService.php:175` (`snapshot()`) | `$company->documents()->get(['id', 'document_type_id', 'status'])` |
| 5 | `app/Services/OrderDocumentService.php:86` | `$order->documents()->create([...])` |
| 6 | `tests/Feature/ComplianceTest.php:28` | `$company->documents()->create(...)` |
| 7 | `tests/Feature/OrderChatLifecycleTest.php:671` | `$order->documents()->where('kind', ...)->count()` |

This is a small, mechanical rename footprint — **not** the "24+4 consumer migration" the gap-plan row describes. That 24+4 figure is the count of files that reference the `CompanyDocument`/`OrderDocument` **classes** (Filament resources, policies, actions, events, notifications, download controllers — 42 files found by `grep -rl 'CompanyDocument|OrderDocument' app`), which read/write those tables directly by class, not through `Company::documents()`/`Order::documents()`. Renaming the two relation methods does not touch any of those 42 files.

### Decision: rename, don't reroute

Rename `Company::documents()` → `companyDocuments()` and `Order::documents()` → `orderDocuments()`, updating the 7 call sites above. This frees `documents()` for `HasDocuments`, which is then added to both models unmodified. Alternative considered: keep `HasDocuments`'s relation under a different name (e.g. `polymorphicDocuments()`) to avoid any rename. Rejected — with only 7 call sites, the rename is cheaper and leaves the *new* consumer-facing name (`documents()`) matching the polymorphic store, which is what every future non-legacy owner (`Species` already, carbon projects/vehicles/drivers/artisans/products later per gap-plan 0.1b's own "Next" column) already expects from `HasDocuments`. Naming the legacy relation `polymorphicDocuments()` would be backwards — the polymorphic one is the strategic name going forward, not the legacy one.

### Decision: `DocumentAccessLog` / `VerificationBadge` / `VerificationRequest` stay non-polymorphic — permanently, not just "not yet"

These three stay hard-wired to `CompanyDocument` in this plan and are explicitly **out of scope**, not deferred-and-forgotten:

- `DocumentAccessLog.company_document_id` records signed-URL issuance and downloads for the compliance audit trail (`DocumentService::logAccess()`). It is a legal/audit record scoped to the company-verification workflow specifically, not a generic "someone downloaded a file" log for every owner type in the system.
- `VerificationBadge.supporting_document_id` and `VerificationRequest.document_snapshot` are internal state of the **verification state machine** (`VerificationService`, `BadgeService`) — a company-specific business process (SIGIF permits, badge issuance) that operates only on `CompanyDocument` rows (`document_type_id`, `sigif_fields`, `expiry_date` — the exact three fields gap-plan 0.1b's prerequisite work already replicated onto `documents` for future backfill, per `Document.php:41-46`, but that replication was for the backfill command, not for these three models).
- Making any of the three polymorphic would require: a new nullable `owner_type`/`owner_id` pair (or reusing `company_document_id` as a dual-purpose column, which is worse), a decision about which store is authoritative during any transition window, and rewriting `BadgeService::approvedDocument()`/`VerificationService::snapshot()`/`approveDocument()`/`rejectDocument()` to branch on owner type — all while `VerificationBadge`/`VerificationRequest` are Company-only concepts today (`belongsTo(Company::class)`, no other owner type exists or is planned for badges/verification requests in `docs/GAP_PLAN.md`). There is no second owner type to be polymorphic *for* yet.
- Verdict: leave these three exactly as they are. If a future gap-plan item introduces verification/badges for a non-Company entity, that item should design the polymorphic shape then, informed by a real second owner — not speculatively here.

### What this plan does NOT do (explicitly out of scope)

- Does not touch any of the 42 files under `app/Filament/Resources/CompanyDocuments/**`, `app/Filament/Exporter/Resources/CompanyDocuments/**`, `app/Actions/Documents/**`, `app/Events/Document*`, `app/Policies/CompanyDocumentPolicy.php`, `app/Http/Controllers/DocumentDownloadController.php`, `app/Http/Controllers/OrderDocumentDownloadController.php`, `app/Notifications/DocumentExpiring.php`. They keep working against `CompanyDocument`/`OrderDocument` unchanged.
- Does not retire, deprecate, or stop writing to `company_documents`/`order_documents`.
- Does not change `DocumentAccessLog`, `VerificationBadge`, or `VerificationRequest` (decision above).
- Does not migrate any Filament panel's create/edit forms onto the polymorphic store. `Company`/`Order` gain the *capability* (`documents()` now points at `Document`) but nothing yet calls `$company->documents()->create()` in application code — that's future work once a concrete non-compliance document type needs it (the gap-plan row's own "Next" column: carbon projects, sponsorship files, vehicles, drivers, products, artisans — none of which exist as models yet).

---

## Task 1: Rename `Company::documents()` to `companyDocuments()`

**Files:**
- Modify: `app/Models/Company.php:174-177`, `:463-471`
- Modify: `app/Services/DocumentService.php:30`
- Modify: `app/Services/BadgeService.php:103`
- Modify: `app/Services/VerificationService.php:175`
- Modify: `tests/Feature/ComplianceTest.php:28`
- Test: `tests/Feature/ComplianceTest.php` (extend existing)

- [ ] **Step 1: Write a failing test asserting the renamed relation exists and still returns `CompanyDocument` rows**

Add to `tests/Feature/ComplianceTest.php` (near the top, alongside the existing document-upload test):

```php
it('exposes company documents under companyDocuments(), not documents()', function () {
    $company = Company::factory()->create();
    $type = DocumentType::factory()->create();

    $doc = $company->companyDocuments()->create([
        'document_type_id' => $type->id,
        'original_filename' => 'permit.pdf',
        'storage_path' => 'companies/1/documents/permit.pdf',
        'disk' => 'documents',
        'status' => DocumentStatus::Pending,
        'visibility' => DocumentVisibility::Private,
    ]);

    expect($company->companyDocuments()->first()->is($doc))->toBeTrue()
        ->and($doc)->toBeInstanceOf(CompanyDocument::class);
});
```

Add the needed `use` statements at the top of the test file if not already present: `use App\Models\CompanyDocument;`, `use App\Models\DocumentType;`, `use App\Enums\DocumentStatus;`, `use App\Enums\DocumentVisibility;`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter="exposes company documents under companyDocuments"`
Expected: FAIL — `Call to undefined method App\Models\Company::companyDocuments()`

- [ ] **Step 3: Rename the relation in `Company.php`**

In `app/Models/Company.php`, change:

```php
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }
```

to:

```php
    public function companyDocuments(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }
```

And change `publicDocuments()` (currently `app/Models/Company.php:463-471`) from:

```php
    /** Public documents only — never the private/admin/buyer-gated ones. */
    public function publicDocuments(): HasMany
    {
        return $this->documents()
            ->where('visibility', DocumentVisibility::Public->value)
            ->where('status', DocumentStatus::Approved->value)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', today()))
            ->orderByDesc('issue_date');
    }
```

to:

```php
    /** Public documents only — never the private/admin/buyer-gated ones. */
    public function publicDocuments(): HasMany
    {
        return $this->companyDocuments()
            ->where('visibility', DocumentVisibility::Public->value)
            ->where('status', DocumentStatus::Approved->value)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', today()))
            ->orderByDesc('issue_date');
    }
```

- [ ] **Step 4: Update the three service call sites**

In `app/Services/DocumentService.php:30`, change:

```php
        $document = $company->documents()->create(array_merge([
```

to:

```php
        $document = $company->companyDocuments()->create(array_merge([
```

In `app/Services/BadgeService.php:103` (`approvedDocument()`), change:

```php
    protected function approvedDocument(Company $company, string $typeKey): ?CompanyDocument
    {
        return $company->documents()
            ->whereHas('documentType', fn ($q) => $q->where('key', $typeKey))
            ->approved()
            ->latest()
            ->first();
    }
```

to:

```php
    protected function approvedDocument(Company $company, string $typeKey): ?CompanyDocument
    {
        return $company->companyDocuments()
            ->whereHas('documentType', fn ($q) => $q->where('key', $typeKey))
            ->approved()
            ->latest()
            ->first();
    }
```

In `app/Services/VerificationService.php:175` (`snapshot()`), change:

```php
    protected function snapshot(Company $company): array
    {
        return $company->documents()->get(['id', 'document_type_id', 'status'])
```

to:

```php
    protected function snapshot(Company $company): array
    {
        return $company->companyDocuments()->get(['id', 'document_type_id', 'status'])
```

- [ ] **Step 5: Update the existing test call site**

In `tests/Feature/ComplianceTest.php:28`, change `$company->documents()->create(...)` to `$company->companyDocuments()->create(...)`.

- [ ] **Step 6: Run the full compliance test file**

Run: `php artisan test tests/Feature/ComplianceTest.php`
Expected: PASS (all tests, including the new one from Step 1)

- [ ] **Step 7: Commit**

```bash
git add app/Models/Company.php app/Services/DocumentService.php app/Services/BadgeService.php app/Services/VerificationService.php tests/Feature/ComplianceTest.php
git commit -m "refactor: rename Company::documents() to companyDocuments()"
```

---

## Task 2: Rename `Order::documents()` to `orderDocuments()`

**Files:**
- Modify: `app/Models/Order.php:98-101`
- Modify: `app/Services/OrderDocumentService.php:86`
- Modify: `tests/Feature/OrderChatLifecycleTest.php:671`
- Test: `tests/Feature/OrderChatLifecycleTest.php` (extend existing)

- [ ] **Step 1: Write a failing test asserting the renamed relation exists and still returns `OrderDocument` rows**

Add near the existing proof-of-delivery assertion in `tests/Feature/OrderChatLifecycleTest.php`:

```php
it('exposes order documents under orderDocuments(), not documents()', function () {
    $order = Order::factory()->create();

    $doc = $order->orderDocuments()->create([
        'kind' => OrderDocumentKind::ProofOfDelivery->value,
        'original_filename' => 'pod.pdf',
        'storage_path' => 'orders/1/documents/pod.pdf',
        'disk' => 'documents',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
    ]);

    expect($order->orderDocuments()->first()->is($doc))->toBeTrue();
});
```

Add `use App\Models\Order;` and `use App\Enums\OrderDocumentKind;` at the top of the test file if not already present (the file already imports `OrderDocumentKind` per the existing usage at line 671).

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter="exposes order documents under orderDocuments"`
Expected: FAIL — `Call to undefined method App\Models\Order::orderDocuments()`

- [ ] **Step 3: Rename the relation in `Order.php`**

In `app/Models/Order.php`, change:

```php
    /** Proof-of-delivery and shipping papers uploaded against this order. */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class)->orderBy('id');
    }
```

to:

```php
    /** Proof-of-delivery and shipping papers uploaded against this order. */
    public function orderDocuments(): HasMany
    {
        return $this->hasMany(OrderDocument::class)->orderBy('id');
    }
```

- [ ] **Step 4: Update `OrderDocumentService.php:86`**

Change:

```php
        return $order->documents()->create([
```

to:

```php
        return $order->orderDocuments()->create([
```

- [ ] **Step 5: Update the existing test call site**

In `tests/Feature/OrderChatLifecycleTest.php:671`, change:

```php
    expect($order->documents()->where('kind', OrderDocumentKind::ProofOfDelivery->value)->count())->toBe(1);
```

to:

```php
    expect($order->orderDocuments()->where('kind', OrderDocumentKind::ProofOfDelivery->value)->count())->toBe(1);
```

- [ ] **Step 6: Run the full order chat lifecycle test file**

Run: `php artisan test tests/Feature/OrderChatLifecycleTest.php`
Expected: PASS (all tests, including the new one from Step 1)

- [ ] **Step 7: Commit**

```bash
git add app/Models/Order.php app/Services/OrderDocumentService.php tests/Feature/OrderChatLifecycleTest.php
git commit -m "refactor: rename Order::documents() to orderDocuments()"
```

---

## Task 3: Give `Company` and `Order` the polymorphic `documents()` relation

**Files:**
- Modify: `app/Models/Company.php`
- Modify: `app/Models/Order.php`
- Test: `tests/Feature/CompanyPolymorphicDocumentsTest.php` (new)

- [ ] **Step 1: Write a failing test for `Company::documents()` returning polymorphic `Document` rows**

Create `tests/Feature/CompanyPolymorphicDocumentsTest.php`:

```php
<?php

use App\Enums\DocumentVerificationStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Order;
use App\Models\OrderDocument;

it('gives Company a polymorphic documents() relation distinct from companyDocuments()', function () {
    $company = Company::factory()->create();
    $type = DocumentType::factory()->create();

    $legacy = $company->companyDocuments()->create([
        'document_type_id' => $type->id,
        'original_filename' => 'legacy.pdf',
        'storage_path' => 'companies/legacy.pdf',
        'disk' => 'documents',
        'status' => \App\Enums\DocumentStatus::Pending,
        'visibility' => \App\Enums\DocumentVisibility::Private,
    ]);

    $polymorphic = Document::create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'type' => 'species_habitat_map', // any generic document type key
        'original_filename' => 'new.pdf',
        'storage_path' => 'companies/new.pdf',
        'disk' => 'documents',
        'verification_status' => DocumentVerificationStatus::Unverified,
    ]);

    expect($company->documents()->count())->toBe(1)
        ->and($company->documents()->first()->is($polymorphic))->toBeTrue()
        ->and($company->documents()->first())->toBeInstanceOf(Document::class)
        ->and($company->companyDocuments()->count())->toBe(1)
        ->and($company->companyDocuments()->first())->toBeInstanceOf(CompanyDocument::class);
});

it('gives Order a polymorphic documents() relation distinct from orderDocuments()', function () {
    $order = Order::factory()->create();

    $legacy = $order->orderDocuments()->create([
        'kind' => \App\Enums\OrderDocumentKind::ProofOfDelivery->value,
        'original_filename' => 'pod.pdf',
        'storage_path' => 'orders/pod.pdf',
        'disk' => 'documents',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
    ]);

    $polymorphic = Document::create([
        'owner_type' => Order::class,
        'owner_id' => $order->id,
        'type' => 'shipping_manifest',
        'original_filename' => 'manifest.pdf',
        'storage_path' => 'orders/manifest.pdf',
        'disk' => 'documents',
        'verification_status' => DocumentVerificationStatus::Unverified,
    ]);

    expect($order->documents()->count())->toBe(1)
        ->and($order->documents()->first()->is($polymorphic))->toBeTrue()
        ->and($order->documents()->first())->toBeInstanceOf(Document::class)
        ->and($order->orderDocuments()->count())->toBe(1)
        ->and($order->orderDocuments()->first())->toBeInstanceOf(OrderDocument::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/CompanyPolymorphicDocumentsTest.php`
Expected: FAIL — `Call to undefined method App\Models\Company::documents()` (and same for `Order`)

- [ ] **Step 3: Add `HasDocuments` to `Company`**

In `app/Models/Company.php`, add the import:

```php
use App\Models\Concerns\HasDocuments;
```

and add the trait to the `use` clause:

```php
class Company extends Model
{
    use HasCapacities, HasDocuments, HasFactory, HasSlug, SoftDeletes;
```

- [ ] **Step 4: Add `HasDocuments` to `Order`**

In `app/Models/Order.php`, add the import:

```php
use App\Models\Concerns\HasDocuments;
```

and add the trait to the `use` clause:

```php
class Order extends Model
{
    use HasDocuments, HasFactory;
```

- [ ] **Step 5: Run the new tests to verify they pass**

Run: `php artisan test tests/Feature/CompanyPolymorphicDocumentsTest.php`
Expected: PASS (both tests)

- [ ] **Step 6: Run the full test suite to confirm no regressions from the rename**

Run: `php artisan test tests/Feature/ComplianceTest.php tests/Feature/OrderChatLifecycleTest.php tests/Feature/CompanyPolymorphicDocumentsTest.php tests/Feature/BackfillDocumentsFromLegacyTablesTest.php`
Expected: PASS (all files)

- [ ] **Step 7: Commit**

```bash
git add app/Models/Company.php app/Models/Order.php tests/Feature/CompanyPolymorphicDocumentsTest.php
git commit -m "feat: add polymorphic documents() relation to Company and Order via HasDocuments"
```

---

## Task 4: Run the complete regression suite

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: PASS — 0 failures. In particular, confirm no Filament resource, policy, or controller test that touches `CompanyDocument`/`OrderDocument` broke, since none of Tasks 1-3 modified those 42 files; a failure here means an undiscovered 8th call site of the old `documents()` name was missed and must be found with `grep -rn "->documents()" app tests` and fixed the same way as Tasks 1-2.

- [ ] **Step 2: Grep-verify no remaining call site of the old ambiguous name survives**

Run: `grep -rn "company->documents()\|\$order->documents()" app tests`
Expected: no output (empty) — every direct call site was renamed in Tasks 1-2.

---

## Task 5: Update the gap-plan row (under the shared-file lock)

**Files:**
- Modify: `docs/GAP_PLAN.md` (0.1b row only)

- [ ] **Step 1: Acquire the lock**

```bash
mkdir C:/laragon/www/cameroontimberhub/.claude/worktrees/company-inquiries-admin-exporter/.test.lock
echo "document-consumer-migration-v2" > C:/laragon/www/cameroontimberhub/.claude/worktrees/company-inquiries-admin-exporter/.test.lock/holder.txt
```

If `mkdir` fails (already locked), wait ~10s and retry.

- [ ] **Step 2: Re-read `docs/GAP_PLAN.md` fresh under the lock, then edit the 0.1b row**

Replace the 0.1b row's status text (re-read the current row first — another agent may have changed adjacent rows) to reflect: relation-name collision resolved (`Company`/`Order` `documents()` renamed to `companyDocuments()`/`orderDocuments()`, `HasDocuments` added to both), `DocumentAccessLog`/`VerificationBadge`/`VerificationRequest` decided to stay non-polymorphic permanently (not a TODO), and that the 42-file Filament/Actions/Events/Policy consumer surface remains intentionally untouched — full cutover of those consumers to the polymorphic store is not part of this item's scope and would need its own gap-plan item once a concrete non-compliance owner exists to justify it.

- [ ] **Step 3: Commit and release the lock**

```bash
git add docs/GAP_PLAN.md
git commit -m "docs: update gap-plan 0.1b for the corrected document-consumer migration v2"
rm -rf C:/laragon/www/cameroontimberhub/.claude/worktrees/company-inquiries-admin-exporter/.test.lock
```

---

## Self-Review Notes

- **Spec coverage:** every item the dispatching prompt asked for is covered — relation-name collision resolved (Task 1-3), the three newly-discovered hard-FK'd models (`DocumentAccessLog`, `VerificationBadge`, `VerificationRequest`) each get an explicit stay-as-is decision with reasoning (Scope Decision section), the plan states the real consumer count (7 call sites, not the original 24+4 estimate which was actually the count of files referencing the *classes*, not the relations) and cites real `file:line` findings throughout.
- **No placeholders:** every step has complete, runnable code — no "add appropriate tests" or "similar to Task N" references.
- **Type consistency:** `companyDocuments()`/`orderDocuments()` return `HasMany` in every task that references them; `documents()` returns `MorphMany` via `HasDocuments` consistently across Tasks 3-4.
