# Transformation Network Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a public "Transformation Network" directory (gap-plan 1.5.3, brief §4.2) — a processor/manufacturer directory separate from the timber-supplier directory, plus a "Find a Transformer" matching flow ("I have 100 m³ of Ayous" → matched processors/manufacturers) built on the already-shipped `Capacity::scopeMatching()` (gap-plan 1.5.4) and `OrganisationType::Processor/Manufacturer` (gap-plan 0.6).

**Architecture:** A single new `TransformationNetworkController` with two read-only actions: `index()` (faceted browse over `Company` filtered to `type IN (processor, manufacturer)`) and `match()` (the "I have X of species Y" flow, composing `Company::scopeHandlingSpecies()` — already on the model — with `Capacity::scopeMatching()` via the `capacities()` morph relation). Two new Blade views render the two actions. No writes, no schema changes, no edits to `Company`, `Capacity`, `OrganisationType`, `CompanyDirectory`, or `DirectoryController` — this is a parallel surface, not a modification of the supplier directory.

**Tech Stack:** Laravel 13 controllers + Blade (matches `DirectoryController`'s pattern — this directory does not need Livewire's live-filter interactivity to ship correctly; a full-page GET per filter change is consistent with how `DirectoryController` itself works as the crawler-visible entry point). Pest feature tests.

---

## Scope Decision

**In scope:**
- `GET /transformation-network` — directory list of verified `processor`/`manufacturer` companies, filterable by region and by capability (free-text match against `Capacity.capability`, covering the brief's business-type list: sawing, kiln drying, planing, moulding, veneering, laminating, CNC, joinery, furniture, doors, flooring, panels, finishing, packaging — these are `capability` values, not a new enum, since `Capacity.capability` is already a free-text column per the 1.5.4 migration).
- `GET /transformation-network/match` — the "I have 100 m³ of Ayous" flow: given a species slug, a quantity, and a period, return processors/manufacturers that (a) handle that species (`Company::scopeHandlingSpecies()`, already built) and (b) have a matching `Capacity` (`Capacity::scopeMatching()`, already built) for that quantity/period.
- Two new Blade views under `resources/views/public/transformation-network/`.
- Two homepage entry points ("Find a Processor" / "Find a Manufacturer") as links into the directory pre-filtered by `type`.
- Feature tests covering: directory excludes suppliers, directory filters by region/capability, match flow returns real matches and correctly excludes companies whose capacity is too small, wrong period, or who don't handle the requested species, and excludes non-processor/manufacturer companies entirely.

**Out of scope (explicitly not touched):** `app/Models/Company.php`, `app/Models/Capacity.php`, `app/Enums/OrganisationType.php`, `app/Livewire/CompanyDirectory.php`, `app/Http/Controllers/Public/DirectoryController.php`, `Product.php`, any `Category`/`Certificate*`/`ContactMessage*`/`Document*`/`ChainedActivity*`/`Consent*`/`Inventory*`/`Rfq*`/`BadgeType.php`/`BadgeService.php` file. Search/facet richness matching `CompanyDirectory`'s Livewire UX (counted checkboxes, `SearchService` integration) is deliberately NOT replicated — that component and its `SearchService` dependency are supplier-directory-specific and out of this module's boundary; a plain-query controller is the right-sized implementation for a read-only directory over existing data.

**Real dependencies confirmed present (read, not modified):**
- `App\Enums\OrganisationType::Processor` / `::Manufacturer` (`app/Enums/OrganisationType.php:20-21`).
- `Company.type` cast to `OrganisationType` (`app/Models/Company.php:71`).
- `Company::scopeHandlingSpecies(array $slugs)` (`app/Models/Company.php:308-313`).
- `Company::capacities(): MorphMany` via the `HasCapacities` trait (`app/Models/Concerns/HasCapacities.php`).
- `Capacity::scopeMatching(string $capability, float $minQuantity, string $period)` (`app/Models/Capacity.php:32-38`).
- `CapacityFactory` and `CompanyFactory` already exist for tests.

**Dev-data reality check (already run):** dev DB currently has 6 `manufacturer` companies and 0 `processor` companies (`type` is null on the other 14 non-logistics companies — pre-brief seed data). The `capacities` table migration is present but not yet run against the dev DB (it runs fresh for every test via `RefreshDatabase`). This plan's tests build their own fixtures and don't depend on dev-data being populated to prove correctness; the final report will additionally query dev data directly to state real counts.

---

## Task 1: Routes and controller skeleton

**Files:**
- Modify: `routes/web.php` (append two new route lines near the existing `/companies` routes, do not touch existing lines)
- Create: `app/Http/Controllers/Public/TransformationNetworkController.php`
- Test: `tests/Feature/TransformationNetworkTest.php`

- [ ] **Step 1: Write the failing test for the index route**

```php
<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Models\Company;

it('lists only verified processor and manufacturer companies', function () {
    $processor = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $manufacturer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'verified_at' => now()]);
    $supplier = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Supplier, 'verified_at' => now()]);
    $unverifiedProcessor = Company::factory()->create(['status' => CompanyStatus::Draft, 'type' => OrganisationType::Processor]);

    $response = $this->get('/transformation-network');

    $response->assertOk();
    $response->assertSee($processor->name);
    $response->assertSee($manufacturer->name);
    $response->assertDontSee($supplier->name);
    $response->assertDontSee($unverifiedProcessor->name);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (inside the test lock, see plan footer): `php artisan test --filter=TransformationNetworkTest`
Expected: FAIL — 404 (route not defined) or class-not-found.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, immediately after the existing `Route::get('/companies', ...)->name('directory');` line (line 42), add:

```php
Route::get('/transformation-network', [TransformationNetworkController::class, 'index'])->name('transformation-network');
Route::get('/transformation-network/match', [TransformationNetworkController::class, 'match'])->name('transformation-network.match');
```

Add the use import near the other `Public` controller imports at the top of the file:

```php
use App\Http\Controllers\Public\TransformationNetworkController;
```

- [ ] **Step 4: Write the controller and view**

Create `app/Http/Controllers/Public/TransformationNetworkController.php`:

```php
<?php

namespace App\Http\Controllers\Public;

use App\Enums\OrganisationType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Brief §4.2's "Transformation Network" — a processor/manufacturer directory
 * kept deliberately separate from the timber-supplier directory
 * (DirectoryController/CompanyDirectory), and the "Find a Transformer" flow
 * ("I have 100 m3 of Ayous" -> matched processors/manufacturers) built on
 * the already-shipped Capacity::scopeMatching() (gap-plan 1.5.4).
 *
 * Read-only against Company/OrganisationType/Capacity/Species -- no writes,
 * no schema changes.
 */
class TransformationNetworkController extends Controller
{
    /** The brief's business-type vocabulary (§4.2) -- Capacity.capability free-text values, not a new enum. */
    public const BUSINESS_TYPES = [
        'Sawing', 'Kiln drying', 'Planing', 'Moulding', 'Veneering', 'Laminating',
        'CNC', 'Joinery', 'Furniture', 'Doors', 'Flooring', 'Panels', 'Finishing', 'Packaging',
    ];

    public function index(Request $request): View
    {
        $type = (string) $request->query('type', '');
        $region = (string) $request->query('region', '');
        $capability = (string) $request->query('capability', '');

        $companies = $this->base()
            ->when(in_array($type, [OrganisationType::Processor->value, OrganisationType::Manufacturer->value], true), fn (Builder $q) => $q->where('type', $type))
            ->when($region !== '', fn (Builder $q) => $q->where('region', $region))
            ->when($capability !== '', fn (Builder $q) => $q->whereHas('capacities', fn (Builder $c) => $c->where('capability', 'ilike', "%{$capability}%")))
            ->orderBy('legal_name')
            ->paginate(12)
            ->withQueryString();

        return view('public.transformation-network.index', [
            'companies' => $companies,
            'businessTypes' => self::BUSINESS_TYPES,
            'type' => $type,
            'region' => $region,
            'capability' => $capability,
        ]);
    }

    public function match(Request $request): View
    {
        $validated = $request->validate([
            'species' => ['nullable', 'string', 'exists:species,slug'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'period' => ['nullable', 'in:day,week,month,quarter,year'],
            'capability' => ['nullable', 'string'],
        ]);

        $species = $validated['species'] ?? null;
        $quantity = isset($validated['quantity']) ? (float) $validated['quantity'] : null;
        $period = $validated['period'] ?? 'month';
        $capability = $validated['capability'] ?? '';

        $matches = ($species !== null && $quantity !== null)
            ? $this->base()
                ->handlingSpecies([$species])
                ->whereHas('capacities', fn (Builder $c) => $c->matching($capability, $quantity, $period))
                ->orderBy('legal_name')
                ->get()
            : collect();

        return view('public.transformation-network.match', [
            'matches' => $matches,
            'speciesOptions' => Species::published()->orderBy('common_name')->get(['slug', 'common_name']),
            'species' => $species,
            'quantity' => $quantity,
            'period' => $period,
            'capability' => $capability,
        ]);
    }

    /** Verified processor/manufacturer companies -- the base set for both actions. */
    private function base(): Builder
    {
        return Company::query()
            ->whereIn('type', [OrganisationType::Processor->value, OrganisationType::Manufacturer->value])
            ->where('status', \App\Enums\CompanyStatus::Verified->value);
    }
}
```

Create `resources/views/public/transformation-network/index.blade.php`:

```blade
<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-2">Transformation Network</h1>
        <p class="text-gray-600 mb-6">Find verified processors and manufacturers in Cameroon's timber transformation sector.</p>

        <div class="flex gap-3 mb-6">
            <a href="{{ route('transformation-network', ['type' => 'processor']) }}" class="px-4 py-2 rounded border {{ $type === 'processor' ? 'bg-primary-600 text-white' : 'bg-white' }}">Find a Processor</a>
            <a href="{{ route('transformation-network', ['type' => 'manufacturer']) }}" class="px-4 py-2 rounded border {{ $type === 'manufacturer' ? 'bg-primary-600 text-white' : 'bg-white' }}">Find a Manufacturer</a>
            <a href="{{ route('transformation-network.match') }}" class="px-4 py-2 rounded border">Find a Transformer for my stock</a>
        </div>

        <form method="get" class="flex flex-wrap gap-3 mb-8">
            @if ($type !== '')
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <input type="text" name="region" value="{{ $region }}" placeholder="Region" class="border rounded px-3 py-2">
            <select name="capability" class="border rounded px-3 py-2">
                <option value="">Any capability</option>
                @foreach ($businessTypes as $businessType)
                    <option value="{{ $businessType }}" @selected($capability === $businessType)>{{ $businessType }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded bg-primary-600 text-white">Filter</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @forelse ($companies as $company)
                <a href="{{ route('companies.show', $company->slug) }}" class="block border rounded p-4 hover:shadow">
                    <div class="font-semibold">{{ $company->name }}</div>
                    <div class="text-sm text-gray-500">{{ $company->type?->label() }} &middot; {{ $company->region }}</div>
                </a>
            @empty
                <p class="text-gray-500">No processors or manufacturers match these filters yet.</p>
            @endforelse
        </div>

        <div class="mt-6">{{ $companies->links() }}</div>
    </div>
</x-app-layout>
```

Create `resources/views/public/transformation-network/match.blade.php`:

```blade
<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-2">Find a Transformer</h1>
        <p class="text-gray-600 mb-6">Tell us what you have and we'll match you with a processor or manufacturer who can take it.</p>

        <form method="get" action="{{ route('transformation-network.match') }}" class="flex flex-wrap gap-3 mb-8">
            <select name="species" class="border rounded px-3 py-2">
                <option value="">Select species</option>
                @foreach ($speciesOptions as $option)
                    <option value="{{ $option->slug }}" @selected($species === $option->slug)>{{ $option->common_name }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" name="quantity" value="{{ $quantity }}" placeholder="Quantity (m3)" class="border rounded px-3 py-2">
            <select name="period" class="border rounded px-3 py-2">
                @foreach (['day', 'week', 'month', 'quarter', 'year'] as $p)
                    <option value="{{ $p }}" @selected($period === $p)>per {{ $p }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded bg-primary-600 text-white">Find matches</button>
        </form>

        <div class="grid grid-cols-1 gap-4">
            @forelse ($matches as $match)
                <a href="{{ route('companies.show', $match->slug) }}" class="block border rounded p-4 hover:shadow">
                    <div class="font-semibold">{{ $match->name }}</div>
                    <div class="text-sm text-gray-500">{{ $match->type?->label() }} &middot; {{ $match->region }}</div>
                </a>
            @empty
                @if ($species && $quantity)
                    <p class="text-gray-500">No processor or manufacturer currently has enough capacity for that.</p>
                @endif
            @endforelse
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TransformationNetworkTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add routes/web.php app/Http/Controllers/Public/TransformationNetworkController.php resources/views/public/transformation-network tests/Feature/TransformationNetworkTest.php
git commit -m "feat: add Transformation Network directory (processor/manufacturer, gap-plan 1.5.3)"
```

---

## Task 2: Region and capability filters

**Files:**
- Modify: `tests/Feature/TransformationNetworkTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TransformationNetworkTest.php`:

```php
it('filters the directory by region', function () {
    $centre = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'region' => 'Centre', 'verified_at' => now()]);
    $littoral = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'region' => 'Littoral', 'verified_at' => now()]);

    $response = $this->get('/transformation-network?region=Centre');

    $response->assertSee($centre->name);
    $response->assertDontSee($littoral->name);
});

it('filters the directory by capability', function () {
    $kilnDryer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $kilnDryer->capacities()->create(['capability' => 'Kiln drying', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $sawyer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $sawyer->capacities()->create(['capability' => 'Sawing', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $response = $this->get('/transformation-network?capability=Kiln drying');

    $response->assertSee($kilnDryer->name);
    $response->assertDontSee($sawyer->name);
});
```

- [ ] **Step 2: Run to verify pass (implementation from Task 1 already covers this)**

Run: `php artisan test --filter=TransformationNetworkTest`
Expected: PASS (Task 1's controller already implements `region` and `capability` filters — this task exists to lock the behaviour under test).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/TransformationNetworkTest.php
git commit -m "test: cover Transformation Network region and capability filters"
```

---

## Task 3: "Find a Transformer" matching flow

**Files:**
- Modify: `tests/Feature/TransformationNetworkTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TransformationNetworkTest.php`:

```php
use App\Models\Species;

it('matches a processor with enough capacity and the right species', function () {
    $ayous = Species::factory()->create(['common_name' => 'Ayous']);

    $matchingProcessor = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $matchingProcessor->species()->attach($ayous);
    $matchingProcessor->capacities()->create(['capability' => 'Sawing', 'quantity' => 200, 'unit' => 'm3', 'period' => 'month']);

    $tooSmall = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'verified_at' => now()]);
    $tooSmall->species()->attach($ayous);
    $tooSmall->capacities()->create(['capability' => 'Sawing', 'quantity' => 50, 'unit' => 'm3', 'period' => 'month']);

    $wrongSpecies = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $wrongSpecies->capacities()->create(['capability' => 'Sawing', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $supplierWithCapacity = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Supplier, 'verified_at' => now()]);
    $supplierWithCapacity->species()->attach($ayous);
    $supplierWithCapacity->capacities()->create(['capability' => 'Sawing', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $response = $this->get("/transformation-network/match?species={$ayous->slug}&quantity=100&period=month");

    $response->assertOk();
    $response->assertSee($matchingProcessor->name);
    $response->assertDontSee($tooSmall->name);
    $response->assertDontSee($wrongSpecies->name);
    $response->assertDontSee($supplierWithCapacity->name);
});

it('shows the match form with no results when nothing has been searched yet', function () {
    $response = $this->get('/transformation-network/match');

    $response->assertOk();
    $response->assertSee('Find a Transformer');
});
```

- [ ] **Step 2: Run to verify pass**

Run: `php artisan test --filter=TransformationNetworkTest`
Expected: PASS (Task 1's `match()` action already implements this — this task locks the behaviour under test, including the negative cases: quantity too low, wrong species, non-transformer company type).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/TransformationNetworkTest.php
git commit -m "test: cover the Find-a-Transformer capacity matching flow"
```

---

## Task 4: Homepage entry points

**Files:**
- Modify: the public homepage view (locate via `route('home')` in `routes/web.php`; likely `resources/views/public/home.blade.php` or similar — confirm exact path before editing)
- Modify: `tests/Feature/TransformationNetworkTest.php`

- [ ] **Step 1: Locate the homepage view**

Run: `grep -n "Route::get('/', " routes/web.php` to find the controller/view, then open that view file to find a natural place near existing directory CTAs (e.g. next to an existing "Browse Suppliers" link/section) to add two new links.

- [ ] **Step 2: Write the failing test**

Append to `tests/Feature/TransformationNetworkTest.php`:

```php
it('links to the Transformation Network from the homepage', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(route('transformation-network', ['type' => 'processor']), false);
    $response->assertSee(route('transformation-network', ['type' => 'manufacturer']), false);
});
```

- [ ] **Step 3: Add the two entry-point links to the homepage view**

Add, near the existing directory CTA section:

```blade
<a href="{{ route('transformation-network', ['type' => 'processor']) }}">Find a Processor</a>
<a href="{{ route('transformation-network', ['type' => 'manufacturer']) }}">Find a Manufacturer</a>
```

(Match the surrounding markup's existing CSS classes rather than inventing new ones — copy the class list from the nearest existing CTA link in that file.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TransformationNetworkTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add tests/Feature/TransformationNetworkTest.php <homepage-view-path>
git commit -m "feat: add Find-a-Processor / Find-a-Manufacturer homepage entry points"
```

---

## Self-Review

**1. Spec coverage:**
- Directory separate from supplier directory: Task 1 (new controller/routes/views, `DirectoryController` untouched). ✓
- "Find a Processor" / "Find a Manufacturer" homepage entry points: Task 4. ✓
- Business types (sawing, kiln drying, ... packaging): `BUSINESS_TYPES` const in Task 1, rendered as the capability filter dropdown. ✓
- Search by location (region): Task 2. ✓
- Search by capability: Task 2. ✓
- Search by species: reachable via the match flow (Task 3); a standalone species facet on the directory index was considered but the brief frames species search under the "I have X of species Y" matching flow specifically, which Task 3 covers with real data.
- "I have 100 m3 of Ayous" -> matched processors/manufacturers using `Capacity::scopeMatching()`: Task 3. ✓
- Product/equipment/MOQ/lead-time/certifications/verification facets: no underlying columns exist on `Company`/`Capacity` for equipment, MOQ, or lead-time (grep confirms `Capacity` only has capability/quantity/unit/period, and `Company` has no MOQ/lead-time/equipment columns) — out of scope for a read-only module against existing data; verification is implicitly enforced by every query requiring `status = verified`.

**2. Placeholder scan:** No TBD/TODO strings; every step has complete, runnable code.

**3. Type consistency:** `TransformationNetworkController::base()` returns `Builder` used identically in `index()` and `match()`. `Capacity::scopeMatching(string $capability, float $minQuantity, string $period)` signature matches every call site (`$c->matching($capability, $quantity, $period)` — argument order and types line up). `Company::scopeHandlingSpecies(array $slugs)` called as `->handlingSpecies([$species])` consistently.

---

## Test-run and lock protocol (every task's test run)

1. `mkdir .test.lock` (retry every ~10s up to ~150 times if it fails).
2. `echo "transformation-network-agent" > .test.lock/holder.txt`.
3. Run the one test command for that step.
4. `rm -rf .test.lock` immediately after.
5. A `.test.lock/holder.txt` older than 15 minutes may be removed and proceeded past (crash recovery) — note this in the final report if it happens.
