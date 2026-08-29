# Made in Cameroon Badge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship gap-plan item 1.5.7 — a "Made in Cameroon" badge with automatic qualifying rules, a marketplace filter, and a public landing page — without forcing it into the existing document-review `BadgeService`.

**Architecture:** Add one computed method `Product::qualifiesForMadeInCameroon(): bool` plus a query scope `Product::scopeMadeInCameroon()`, both derived from real data already on `Company` (country_code, type, verified_at/status) — no new table, no manual issuance workflow. Add a new `MadeInCameroonController` with its own route (`/made-in-cameroon`) rendering a dedicated landing page that lists qualifying products, independent of the existing `ProductCatalogueService`/Livewire catalogue so as not to touch shared marketplace-filter code other agents are editing this batch.

**Tech Stack:** Laravel 13, Eloquent query scopes, Blade views, PHPUnit feature tests.

---

## Scope Decision

**Investigation performed (read in full before writing this plan):**

- `app/Enums/BadgeType.php` — 8 existing cases (VerifiedCompany, VerifiedExporter, SigifRegistered, LegalTimberSupplier, ExportReady, CitesApproved, SustainabilityProfile, PremiumMember). All are **company-level** badges.
- `app/Services/BadgeService.php` — `canIssue()`/`issue()` are built entirely around `config('compliance.badge_requirements.<type>')`: a list of `document_type` keys that must have an **approved, unexpired `CompanyDocument`** on file. `issue()` creates a `VerificationBadge` row tied to a `Company`, backed by `supporting_document_id`, with a human `verified_by` issuer and an audit-logged `VerificationRequest`. This is fundamentally a **manual document-review workflow**: an admin approves documents, then a badge is (re)issued from them. `PremiumMember` is explicitly excluded from this loop as "plan-gated, not document-backed" — proof the service already special-cases badges that don't fit its document loop, by simply returning `false` from `canIssue()` rather than reshaping the loop.
- `app/Models/Product.php` — a supplier's marketplace listing. Already has a `trustBadges()` method that derives **UI-only trust chips** (not `VerificationBadge` rows) from real signals on the listing/species/company — an established, additive pattern for computed/derived product-level badges.
- `app/Models/Company.php` — has `country_code` (default `'CM'`, indexed), `type` (`OrganisationType` — includes `Manufacturer`, `Processor`, `Artisan`), and `status` (`CompanyStatus::Verified`) plus `scopePubliclyVisible()` as the single gate for public company exposure.
- `app/Enums/OrganisationType.php` — confirms `Manufacturer`, `Processor`, `Artisan` cases exist (added in gap-plan item 0.6) — these are the domestic-manufacture roles the brief's "Made in Cameroon" wording implies, as distinct from `Supplier` (raw material, not necessarily transformed in-country) or `Buyer`/`Retailer`/etc.

**Decision: do NOT add a `BadgeType::MadeInCameroon` case.** The brief calls this a badge with "qualifying rules" — i.e. automatic, derived eligibility — not a manually-reviewed, document-backed badge. Forcing it through `BadgeService::canIssue()` would require either (a) inventing a fake `compliance.badge_requirements` document-type entry with no real document behind it (dishonest — there is no document that proves "made in Cameroon", it's a computed fact about company type + country + verification), or (b) special-casing `MadeInCameroon` inside `canIssue()`/`issue()` the way `PremiumMember` is special-cased, which still produces a manually-issued `VerificationBadge` row for something that should recompute itself the moment company data changes — stale state for no reason. This is exactly the forced-cutover mistake flagged in items 0.2b/0.1b.

**Chosen mechanism:** a computed eligibility check that lives where the fact actually lives — on `Product`, since "Made in Cameroon" is a **product-level claim** (a specific listing was made domestically), not a blanket company badge (a company could sell both domestically-made goods and re-exported/imported goods in principle, though today every company is Cameroon-based; scoping to Product is still the more honest, brief-aligned choice and keeps qualification per-listing, matching how `trustBadges()` already works).

**Qualifying rule** (`Product::qualifiesForMadeInCameroon()`):
1. `$this->company` exists, `company->country_code === 'CM'`,
2. `company->status === CompanyStatus::Verified` (real verification, not a claim),
3. `company->type` is one of `OrganisationType::Manufacturer`, `OrganisationType::Processor`, `OrganisationType::Artisan` (the domestic-transformation roles from item 0.6) — a raw `Supplier` of unprocessed logs is not "made in Cameroon" manufacture,
4. `$this->status === ProductStatus::Active` (only live listings qualify, matching every other public-facing scope in this codebase).

This needs no new migration, no new table, and recomputes correctly the instant company data changes — no stale manually-issued row to revoke.

**Explicit exclusion — product QR / transformation history:** The brief additionally says a "product QR shows transformation history." This is **out of scope for this plan.** It depends on shipment/checkpoint tracking (gap-plan items 1.5.10/1.5.11), which do not exist in this codebase yet — there is no chain-of-custody/checkpoint model to render a transformation history from. Implementing it now would mean building a fake/empty history view with nothing real behind it. This is tracked as a follow-up in `docs/GAP_PLAN.md`'s 1.5.7 row (see Task 5) to be picked up once 1.5.10/1.5.11 land.

**Files touched (module boundary respected):**
- Modify: `app/Models/Product.php` — ONE new method `qualifiesForMadeInCameroon()` and ONE new scope `scopeMadeInCameroon()`, added alongside existing content, nothing else touched. File is re-read fresh immediately before editing per the shared-hotspot coordination rule.
- Create: `app/Http/Controllers/Public/MadeInCameroonController.php`
- Create: `resources/views/public/made-in-cameroon/index.blade.php`
- Modify: `routes/web.php` — one new GET route line.
- Create: `tests/Feature/MadeInCameroonBadgeTest.php`
- Modify: `docs/GAP_PLAN.md` — 1.5.7 row only (under GAP_PLAN.md lock protocol).

No other file is touched. `BadgeService.php`, `BadgeType.php`, `Company.php`, `OrganisationType.php` are read-only references, never edited.

---

## Task 1: `Product::qualifiesForMadeInCameroon()` and `scopeMadeInCameroon()`

**Files:**
- Modify: `app/Models/Product.php`
- Test: `tests/Feature/MadeInCameroonBadgeTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;

test('a product qualifies for Made in Cameroon when its company is a verified domestic manufacturer', function () {
    $company = Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Manufacturer,
    ]);

    $product = Product::factory()->for($company)->create([
        'status' => ProductStatus::Active,
    ]);

    expect($product->qualifiesForMadeInCameroon())->toBeTrue();
});

test('processor and artisan companies also qualify', function () {
    foreach ([OrganisationType::Processor, OrganisationType::Artisan] as $type) {
        $company = Company::factory()->create([
            'country_code' => 'CM',
            'status' => CompanyStatus::Verified,
            'type' => $type,
        ]);

        $product = Product::factory()->for($company)->create(['status' => ProductStatus::Active]);

        expect($product->qualifiesForMadeInCameroon())->toBeTrue("failed for {$type->value}");
    }
});

test('a raw supplier does not qualify even when verified and domestic', function () {
    $company = Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Supplier,
    ]);

    $product = Product::factory()->for($company)->create(['status' => ProductStatus::Active]);

    expect($product->qualifiesForMadeInCameroon())->toBeFalse();
});

test('an unverified manufacturer does not qualify', function () {
    $company = Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Pending,
        'type' => OrganisationType::Manufacturer,
    ]);

    $product = Product::factory()->for($company)->create(['status' => ProductStatus::Active]);

    expect($product->qualifiesForMadeInCameroon())->toBeFalse();
});

test('a non-Cameroon company does not qualify', function () {
    $company = Company::factory()->create([
        'country_code' => 'NG',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Manufacturer,
    ]);

    $product = Product::factory()->for($company)->create(['status' => ProductStatus::Active]);

    expect($product->qualifiesForMadeInCameroon())->toBeFalse();
});

test('a draft product does not qualify even if the company would otherwise pass', function () {
    $company = Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Manufacturer,
    ]);

    $product = Product::factory()->for($company)->create(['status' => ProductStatus::Draft]);

    expect($product->qualifiesForMadeInCameroon())->toBeFalse();
});

test('scopeMadeInCameroon returns only qualifying products', function () {
    $qualifying = Product::factory()->for(Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Manufacturer,
    ]))->create(['status' => ProductStatus::Active]);

    $notQualifying = Product::factory()->for(Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Supplier,
    ]))->create(['status' => ProductStatus::Active]);

    $results = Product::query()->madeInCameroon()->get();

    expect($results->pluck('id'))->toContain($qualifying->id)
        ->and($results->pluck('id'))->not->toContain($notQualifying->id);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Acquire the test lock first (see repo-wide lock protocol), then:

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=MadeInCameroonBadgeTest`
Expected: FAIL — `qualifiesForMadeInCameroon()` / `scopeMadeInCameroon` do not exist.

Release the lock.

- [ ] **Step 3: Implement — re-read `app/Models/Product.php` fresh first (shared hotspot with 1.5.1), then add**

Add these two methods to `app/Models/Product.php`, right after `scopeFeatured()` (the last existing scope), keeping every existing line untouched:

```php
    /**
     * "Made in Cameroon" is a computed fact, not a manually-reviewed document
     * badge — it does not go through BadgeService/VerificationBadge. A
     * listing qualifies when its company is a verified, Cameroon-based
     * domestic-transformation business (manufacturer, processor or artisan —
     * not a raw-material supplier) and the listing itself is live.
     */
    public function qualifiesForMadeInCameroon(): bool
    {
        $company = $this->company;

        if (! $company || $this->status !== ProductStatus::Active) {
            return false;
        }

        return $company->country_code === 'CM'
            && $company->status === \App\Enums\CompanyStatus::Verified
            && in_array($company->type, [
                \App\Enums\OrganisationType::Manufacturer,
                \App\Enums\OrganisationType::Processor,
                \App\Enums\OrganisationType::Artisan,
            ], true);
    }

    /** Query-level equivalent of {@see qualifiesForMadeInCameroon()}, for listings. */
    public function scopeMadeInCameroon(Builder $query): Builder
    {
        return $query
            ->where('status', ProductStatus::Active)
            ->whereHas('company', fn (Builder $c) => $c
                ->where('country_code', 'CM')
                ->where('status', \App\Enums\CompanyStatus::Verified->value)
                ->whereIn('type', [
                    \App\Enums\OrganisationType::Manufacturer->value,
                    \App\Enums\OrganisationType::Processor->value,
                    \App\Enums\OrganisationType::Artisan->value,
                ]));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Acquire lock, run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=MadeInCameroonBadgeTest`
Expected: PASS (7 tests). Release lock.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add app/Models/Product.php tests/Feature/MadeInCameroonBadgeTest.php
git commit -m "feat: add Product::qualifiesForMadeInCameroon computed badge eligibility"
```

---

## Task 2: Landing page controller, route, and view

**Files:**
- Create: `app/Http/Controllers/Public/MadeInCameroonController.php`
- Create: `resources/views/public/made-in-cameroon/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MadeInCameroonBadgeTest.php` (append)

- [ ] **Step 1: Write the failing tests (append to the same file)**

```php
test('the Made in Cameroon landing page lists only qualifying products', function () {
    $qualifying = \App\Models\Product::factory()->for(\App\Models\Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Manufacturer,
        'legal_name' => 'Douala Furnitures SARL',
    ]))->create(['status' => ProductStatus::Active, 'name' => 'Qualifying Iroko Table']);

    $notQualifying = \App\Models\Product::factory()->for(\App\Models\Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Supplier,
    ]))->create(['status' => ProductStatus::Active, 'name' => 'Raw Logs Lot']);

    $response = $this->get(route('made-in-cameroon'));

    $response->assertOk();
    $response->assertSee('Qualifying Iroko Table');
    $response->assertDontSee('Raw Logs Lot');
});

test('the Made in Cameroon landing page renders with zero qualifying products', function () {
    $response = $this->get(route('made-in-cameroon'));

    $response->assertOk();
});
```

- [ ] **Step 2: Run to verify failure**

Acquire lock, run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=MadeInCameroonBadgeTest`
Expected: FAIL — route `made-in-cameroon` not defined. Release lock.

- [ ] **Step 3: Implement the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\View\View;

/**
 * Public landing page for the "Made in Cameroon" badge (gap-plan 1.5.7).
 * Lists listings that pass Product::qualifiesForMadeInCameroon() — a
 * computed fact, so this page always reflects live company/product state
 * with nothing to manually issue or revoke.
 *
 * NOTE: the brief also mentions a product QR showing transformation
 * history. That is explicitly OUT OF SCOPE here — it depends on
 * shipment/checkpoint tracking (gap-plan 1.5.10/1.5.11), which does not
 * exist yet. Tracked as a follow-up in docs/GAP_PLAN.md.
 */
class MadeInCameroonController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->madeInCameroon()
            ->with(['company:id,slug,legal_name,trade_name,logo_path', 'species:id,slug,common_name', 'images'])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('public.made-in-cameroon.index', [
            'products' => $products,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Made in Cameroon', 'url' => route('made-in-cameroon')],
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, immediately after the existing marketplace routes block:

```php
// Product marketplace (static segment before the CMS slug catch-all).
Route::get('/marketplace', [ProductController::class, 'index'])->name('marketplace');
Route::get('/marketplace/{product:slug}', [ProductController::class, 'show'])->name('products.show');

// "Made in Cameroon" badge landing page (gap-plan 1.5.7).
Route::get('/made-in-cameroon', [\App\Http\Controllers\Public\MadeInCameroonController::class, 'index'])->name('made-in-cameroon');
```

- [ ] **Step 5: Implement the view**

```blade
<x-app-layout>
    <x-slot:title>Made in Cameroon</x-slot:title>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Made in Cameroon</h1>
            <p class="mt-2 max-w-2xl text-gray-600">
                Listings on this page come from verified Cameroon-based manufacturers, processors
                and artisans — real in-country transformation, not raw material resale.
            </p>
        </div>

        @if ($products->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 p-10 text-center text-gray-500">
                No qualifying listings yet.
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run tests to verify they pass**

Acquire lock, run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=MadeInCameroonBadgeTest`
Expected: PASS (9 tests total). Release lock.

If `x-app-layout` or `x-product-card` component names differ from what actually exists, check `resources/views/components/product-card.blade.php` and an existing public page (e.g. `resources/views/public/products/index.blade.php`) for the real layout component name and prop signature before finalizing this step, and adjust to match exactly.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Public/MadeInCameroonController.php resources/views/public/made-in-cameroon/index.blade.php routes/web.php tests/Feature/MadeInCameroonBadgeTest.php
git commit -m "feat: add Made in Cameroon landing page with automatic qualifying filter"
```

---

## Task 3: Full-suite regression check

- [ ] **Step 1: Acquire the test lock**, confirm holder.txt, run the mkdir lock per protocol.

- [ ] **Step 2: Verify --env=testing resolves to the test DB**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"`
Expected output contains `cameroontimberhub_testing`.

- [ ] **Step 3: Run the full suite**

Run: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test`
Expected: all tests pass, count >= 875 + 9 new = 884.

- [ ] **Step 4: Release the lock** (`rm -rf .test.lock`).

- [ ] **Step 5: Self-review the diff**

Run `git diff master...HEAD --stat` and confirm only the files listed in the Scope Decision's "Files touched" section were changed. Confirm `Company.php`, `BadgeType.php`, `BadgeService.php`, `OrganisationType.php` are untouched.

---

## Task 4: Record the GAP_PLAN.md update and follow-up

**Files:**
- Modify: `docs/GAP_PLAN.md` (own row only, under lock protocol)

- [ ] **Step 1: Acquire the GAP_PLAN.md lock** (same mkdir mechanism as the test lock, separate directory if the plan's existing convention uses one — otherwise reuse `.test.lock` sequentially, never concurrently with a test run).

- [ ] **Step 2: Re-read `docs/GAP_PLAN.md` fresh**, locate the 1.5.7 row.

- [ ] **Step 3: Edit only that row** to mark it done, summarizing: computed `Product::qualifiesForMadeInCameroon()` + `scopeMadeInCameroon()`, `/made-in-cameroon` landing page, filter via the new scope. Add an explicit follow-up note: "Product QR transformation-history display deferred — depends on 1.5.10/1.5.11 (shipment/checkpoint tracking), which do not exist yet."

- [ ] **Step 4: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "docs: mark gap-plan 1.5.7 done, track QR transformation-history as follow-up"
```

- [ ] **Step 5: Release the GAP_PLAN.md lock.**

---

## Self-Review

**1. Spec coverage:** Badge/qualifying rules → Task 1. Filter → `scopeMadeInCameroon()` used by the landing page query in Task 2 (a dedicated filtered view, satisfying "a filter" without touching the shared marketplace facet system other agents are editing). Landing page → Task 2. QR/transformation history → explicitly excluded in Scope Decision and tracked in Task 4. All brief requirements for 1.5.7 addressed except the explicitly-excluded QR piece.

**2. Placeholder scan:** No TBD/TODO markers; every step has complete code.

**3. Type consistency:** `qualifiesForMadeInCameroon()` and `scopeMadeInCameroon()` names match between Task 1 definition and Task 2 controller usage (`Product::query()->madeInCameroon()`). `CompanyStatus::Verified`, `OrganisationType::{Manufacturer,Processor,Artisan}` used consistently across tests and implementation.
