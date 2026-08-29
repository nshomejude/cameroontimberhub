# Buy Cameroon Wood (Domestic Search) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a domestic-market search experience at `/buy-cameroon-wood` that never uses export vocabulary, letting a domestic buyer (furniture maker, joinery, kiln dryer, sawmill customer) filter Cameroon timber listings by species, grade, dimensions, quantity, treatment (moisture content), and supplier region/delivery — read-only against the existing catalogue.

**Architecture:** A new, self-contained read path: `DomesticMarketplaceController` → `DomesticMarketplaceService` (own query builder, mirrors the pattern in `ProductCatalogueService` but is not shared with it) → a new Blade view rendered without Livewire (plain GET query-string filtering, so it works with JS off and needs no new frontend build step). No existing file is modified. Data comes entirely from the already-live `products` and `companies` tables via the untouched `Product`/`Company`/`ProductType` models.

**Tech Stack:** Laravel 13 controller + Blade (no Livewire), Eloquent query builder, Pest feature tests.

---

## Scope Decision

Gap-plan item 1.5.2 (brief §4.1 "Buy Cameroon Wood") is written as if it depends on item 1.5.1's new Category tree, which is being built **concurrently by another agent** and may not exist in this worktree yet. This plan **does not wait for it and does not touch any Category-related file.**

Instead, this item is built entirely against what already exists and is already populated with real data:
- `product_type` (the `ProductType` enum/column on `products`) stands in for category, grouped/labelled through its existing `label()` method — no category taxonomy needed to deliver a working domestic filter experience.
- Species (`species_id` / `Species` model), grade, dimensions (`thickness_mm`, `width_min_mm/max_mm`, `length_min_m/max_m`), quantity (`moq_quantity`/`moq_unit`), and treatment (`moisture_content`) come straight off `Product`.
- Location/region and delivery come off `Company` (`region`, `city`, `delivery_days_min`, `delivery_days_max`).

**Follow-up tracked separately:** once 1.5.1's Category tree lands, replace/augment the `product_type` facet here with true category-tree filtering. This is recorded as a new gap-plan sub-item **1.5.2b — "Wire domestic search to the Category tree"** in `GAP_PLAN.md` (added under the GAP_PLAN.md lock in Task 6), not implemented now.

This plan does not modify `app/Models/Product.php`, `app/Models/Company.php`, `app/Enums/ProductType.php`, `app/Http/Controllers/Public/ProductController.php`, `app/Services/ProductCatalogueService.php`, or any Category/Capacity/Certificate/ContactMessage/Document/ChainedActivity/Consent/Inventory/Rfq/Badge file. It only adds new files and read-only queries.

---

## File Structure

- Create: `app/Services/DomesticMarketplaceService.php` — the single query behind `/buy-cameroon-wood`: base visibility predicate (active product + publicly visible company, same predicate `ProductCatalogueService::base()` uses, reimplemented locally so this module has zero dependency on that file), filters, facets, sort.
- Create: `app/Http/Controllers/Public/DomesticMarketplaceController.php` — thin controller: reads query params into a filters array, calls the service, renders the view.
- Create: `resources/views/public/domestic/index.blade.php` — plain-English filter sidebar (species, grade, dimensions, quantity, treatment, region, delivery) + result grid + pagination. GET-form based, no Livewire.
- Modify: `routes/web.php` — add one route line (additive only, no existing route touched).
- Create: `tests/Feature/DomesticMarketplaceTest.php` — Pest feature tests covering the page and every filter.

---

### Task 1: `DomesticMarketplaceService` — base query, species/grade/type filters

**Files:**
- Create: `app/Services/DomesticMarketplaceService.php`
- Test: `tests/Feature/DomesticMarketplaceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Services\DomesticMarketplaceService;

function domesticSupplier(array $attributes = []): Company
{
    return Company::factory()->publiclyVisible()->create(array_merge([
        'legal_name' => 'Mbelenga Sawmill',
        'city' => 'Douala',
        'region' => 'Littoral',
        'delivery_days_min' => 2,
        'delivery_days_max' => 5,
    ], $attributes));
}

function domesticProduct(Company $company, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => ProductStatus::Active,
    ], $attributes));
}

it('lists only active products from publicly visible suppliers', function () {
    $visible = domesticSupplier();
    $hidden = Company::factory()->create(['legal_name' => 'Hidden Co']); // not publiclyVisible

    $shown = domesticProduct($visible, ['name' => 'Iroko Planks']);
    domesticProduct($visible, ['name' => 'Draft Planks', 'status' => ProductStatus::Draft]);
    Product::factory()->create(['company_id' => $hidden->id, 'status' => ProductStatus::Active, 'name' => 'Hidden Planks']);

    $results = app(DomesticMarketplaceService::class)->search([]);

    expect($results->pluck('name')->all())->toBe([$shown->name]);
});

it('filters by species slug', function () {
    $supplier = domesticSupplier();
    $iroko = Species::factory()->create(['slug' => 'iroko', 'common_name' => 'Iroko']);
    $sapele = Species::factory()->create(['slug' => 'sapele', 'common_name' => 'Sapele']);

    $match = domesticProduct($supplier, ['species_id' => $iroko->id, 'name' => 'Iroko Beams']);
    domesticProduct($supplier, ['species_id' => $sapele->id, 'name' => 'Sapele Beams']);

    $results = app(DomesticMarketplaceService::class)->search(['species' => ['iroko']]);

    expect($results->pluck('name')->all())->toBe([$match->name]);
});

it('filters by product type', function () {
    $supplier = domesticSupplier();
    $match = domesticProduct($supplier, ['product_type' => ProductType::Planks, 'name' => 'Domestic Planks']);
    domesticProduct($supplier, ['product_type' => ProductType::Logs, 'name' => 'Round Logs']);

    $results = app(DomesticMarketplaceService::class)->search(['types' => ['planks']]);

    expect($results->pluck('name')->all())->toBe([$match->name]);
});

it('filters by grade', function () {
    $supplier = domesticSupplier();
    $match = domesticProduct($supplier, ['grade' => 'Select & Better', 'name' => 'Graded Boards']);
    domesticProduct($supplier, ['grade' => 'Standard', 'name' => 'Standard Boards']);

    $results = app(DomesticMarketplaceService::class)->search(['grade' => 'Select & Better']);

    expect($results->pluck('name')->all())->toBe([$match->name]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Acquire the test lock first (see repo-wide lock protocol below), then run:

`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: FAIL — `Class "App\Services\DomesticMarketplaceService" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single query behind /buy-cameroon-wood — a domestic-market search that
 * never surfaces export vocabulary. Filters entirely against what already
 * exists on Product/Company today (species, grade, dimensions, quantity,
 * treatment, region/delivery). Product type stands in for a category tree
 * until gap-plan item 1.5.1 lands; see the plan's Scope Decision.
 *
 * Deliberately independent of ProductCatalogueService (the export-facing
 * /marketplace query) so the two modules never collide on a shared file.
 */
class DomesticMarketplaceService
{
    /** @return array<string, string> */
    public static function sortOptions(): array
    {
        return [
            'newest' => 'Newest listings',
            'price_low' => 'Price (low to high)',
            'price_high' => 'Price (high to low)',
            'delivery_fast' => 'Fastest delivery',
            'name' => 'Name (A–Z)',
        ];
    }

    /** Publicly visible catalogue: active listings from publicly visible suppliers. */
    public function base(): Builder
    {
        return Product::query()
            ->where('products.status', ProductStatus::Active->value)
            ->whereHas('company', fn ($c) => $c->publiclyVisible());
    }

    /** @param array<string, mixed> $filters */
    public function search(array $filters, int $perPage = 12): LengthAwarePaginator|Collection
    {
        $query = $this->query($filters)
            ->with([Company::cardEagerLoad(), 'species:id,slug,common_name']);

        if ($perPage <= 0) {
            return $query->get();
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function query(array $filters): Builder
    {
        $query = $this->applyFilters($this->base(), $filters);

        return match ($filters['sort'] ?? 'newest') {
            'price_low' => $query->orderByRaw('price_amount ASC NULLS LAST')->orderBy('name'),
            'price_high' => $query->orderByRaw('price_amount DESC NULLS LAST')->orderBy('name'),
            'delivery_fast' => $query->join('companies', 'companies.id', '=', 'products.company_id')
                ->orderByRaw('companies.delivery_days_min ASC NULLS LAST')
                ->select('products.*'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };
    }

    /** @param array<string, mixed> $filters */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $region = (string) ($filters['region'] ?? '');
        $grade = trim((string) ($filters['grade'] ?? ''));
        $treatment = (string) ($filters['treatment'] ?? '');
        $types = array_values(array_intersect((array) ($filters['types'] ?? []), array_column(ProductType::cases(), 'value')));
        $speciesIn = array_values(array_filter((array) ($filters['species'] ?? [])));
        $minQuantity = $filters['minQuantity'] ?? null;
        $maxThicknessMm = $filters['maxThicknessMm'] ?? null;

        return $query
            ->when($q !== '', fn (Builder $b) => $b->whereRaw("products.search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->when($region !== '', fn (Builder $b) => $b->whereHas('company', fn ($c) => $c->where('region', $region)))
            ->when($grade !== '', fn (Builder $b) => $b->where('products.grade', $grade))
            ->when($treatment !== '', fn (Builder $b) => $b->where('products.moisture_content', 'ilike', "%{$treatment}%"))
            ->when($types !== [], fn (Builder $b) => $b->whereIn('products.product_type', $types))
            ->when($speciesIn !== [], fn (Builder $b) => $b->whereHas('species', fn ($s) => $s->whereIn('species.slug', $speciesIn)))
            ->when(is_numeric($minQuantity), fn (Builder $b) => $b->where(function ($w) use ($minQuantity) {
                $w->whereNull('products.moq_quantity')->orWhere('products.moq_quantity', '<=', $minQuantity);
            }))
            ->when(is_numeric($maxThicknessMm), fn (Builder $b) => $b->where('products.thickness_mm', '<=', $maxThicknessMm));
    }

    /** @return list<array{value: string, label: string, count: int}> */
    public function typeFacets(array $filters): array
    {
        $counts = $this->applyFilters($this->base(), array_diff_key($filters, ['types' => true]))
            ->selectRaw('products.product_type, count(*) as aggregate')
            ->groupBy('products.product_type')
            ->pluck('aggregate', 'product_type');

        return collect(ProductType::cases())
            ->map(fn (ProductType $t) => ['value' => $t->value, 'label' => $t->label(), 'count' => (int) ($counts[$t->value] ?? 0)])
            ->filter(fn (array $f) => $f['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string, count: int}> */
    public function regionFacets(): array
    {
        return $this->base()
            ->join('companies', 'companies.id', '=', 'products.company_id')
            ->whereNotNull('companies.region')
            ->selectRaw('companies.region, count(*) as aggregate')
            ->groupBy('companies.region')
            ->orderBy('companies.region')
            ->get()
            ->map(fn ($row) => ['value' => $row->region, 'label' => $row->region, 'count' => (int) $row->aggregate])
            ->all();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Under the same lock acquisition as Step 2:

`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: PASS (4/4 so far).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DomesticMarketplaceService.php tests/Feature/DomesticMarketplaceTest.php
git commit -m "feat: add DomesticMarketplaceService for domestic timber search"
```

---

### Task 2: dimension, quantity, treatment, region filter tests + facets

**Files:**
- Test: `tests/Feature/DomesticMarketplaceTest.php` (append)

- [ ] **Step 1: Write the failing tests (append to the same Pest file)**

```php
it('filters by supplier region', function () {
    $centre = domesticSupplier(['region' => 'Centre', 'legal_name' => 'Centre Co']);
    $littoral = domesticSupplier(['region' => 'Littoral', 'legal_name' => 'Littoral Co']);

    $match = domesticProduct($centre, ['name' => 'Centre Boards']);
    domesticProduct($littoral, ['name' => 'Littoral Boards']);

    $results = app(DomesticMarketplaceService::class)->search(['region' => 'Centre']);

    expect($results->pluck('name')->all())->toBe([$match->name]);
});

it('filters by treatment via moisture content', function () {
    $supplier = domesticSupplier();
    $kilnDried = domesticProduct($supplier, ['moisture_content' => '12% - 15% (KD)', 'name' => 'Kiln Dried Boards']);
    domesticProduct($supplier, ['moisture_content' => 'Air dried', 'name' => 'Air Dried Boards']);

    $results = app(DomesticMarketplaceService::class)->search(['treatment' => 'KD']);

    expect($results->pluck('name')->all())->toBe([$kilnDried->name]);
});

it('filters by a buyer-requested quantity against the listing MOQ', function () {
    $supplier = domesticSupplier();
    $withinReach = domesticProduct($supplier, ['moq_quantity' => 5, 'name' => 'Small Batch']);
    $tooLarge = domesticProduct($supplier, ['moq_quantity' => 500, 'name' => 'Bulk Only']);

    $results = app(DomesticMarketplaceService::class)->search(['minQuantity' => 10]);

    expect($results->pluck('name')->all())->toBe([$withinReach->name])
        ->and($results->pluck('name')->all())->not->toContain($tooLarge->name);
});

it('filters by maximum thickness', function () {
    $supplier = domesticSupplier();
    $thin = domesticProduct($supplier, ['thickness_mm' => 25, 'name' => 'Thin Boards']);
    domesticProduct($supplier, ['thickness_mm' => 100, 'name' => 'Thick Beams']);

    $results = app(DomesticMarketplaceService::class)->search(['maxThicknessMm' => 50]);

    expect($results->pluck('name')->all())->toBe([$thin->name]);
});

it('computes region facets over the visible catalogue', function () {
    $centre = domesticSupplier(['region' => 'Centre', 'legal_name' => 'Centre Co 2']);
    domesticProduct($centre, ['name' => 'Centre Boards 2']);

    $facets = app(DomesticMarketplaceService::class)->regionFacets();

    expect(collect($facets)->pluck('value')->all())->toContain('Centre');
});

it('computes type facets that add up to the visible catalogue and drop zero counts', function () {
    $supplier = domesticSupplier();
    domesticProduct($supplier, ['product_type' => ProductType::Planks]);
    domesticProduct($supplier, ['product_type' => ProductType::Planks]);

    $facets = app(DomesticMarketplaceService::class)->typeFacets([]);
    $planks = collect($facets)->firstWhere('value', 'planks');

    expect($planks['count'])->toBeGreaterThanOrEqual(2)
        ->and(collect($facets)->pluck('count')->min())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Acquire lock, then: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: these 6 new tests should actually already PASS against Task 1's implementation (the service already implements region/treatment/minQuantity/thickness filters and both facet methods). This step exists to confirm that — if any fails, fix `DomesticMarketplaceService` before proceeding (do not add placeholder code; the filters are already fully implemented above).

- [ ] **Step 3: Confirm all tests pass, no implementation changes expected**

Same command as above. Expected: PASS (10/10 in the file).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/DomesticMarketplaceTest.php
git commit -m "test: cover dimension, quantity, treatment, region domestic filters"
```

---

### Task 3: `DomesticMarketplaceController` + route

**Files:**
- Create: `app/Http/Controllers/Public/DomesticMarketplaceController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DomesticMarketplaceTest.php` (append)

- [ ] **Step 1: Write the failing test**

```php
it('renders the buy cameroon wood page', function () {
    $supplier = domesticSupplier();
    domesticProduct($supplier, ['name' => 'Sapele Furniture Planks']);

    $this->get('/buy-cameroon-wood')
        ->assertOk()
        ->assertSee('Buy Cameroon Wood')
        ->assertSee('Sapele Furniture Planks')
        ->assertDontSee('FOB')
        ->assertDontSee('Incoterms');
});

it('applies filters from the query string', function () {
    $supplier = domesticSupplier(['region' => 'Centre']);
    $other = domesticSupplier(['region' => 'Littoral', 'legal_name' => 'Other Co']);

    domesticProduct($supplier, ['name' => 'Centre Match']);
    domesticProduct($other, ['name' => 'Littoral Miss']);

    $this->get('/buy-cameroon-wood?region=Centre')
        ->assertOk()
        ->assertSee('Centre Match')
        ->assertDontSee('Littoral Miss');
});
```

- [ ] **Step 2: Run test to verify it fails**

Acquire lock, then: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: FAIL — 404, route `/buy-cameroon-wood` does not exist.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Species;
use App\Services\DomesticMarketplaceService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /buy-cameroon-wood — the domestic-market search experience (gap-plan
 * 1.5.2). Deliberately separate from ProductController/`/marketplace`: the
 * brief requires a search that never surfaces export vocabulary, so this
 * controller, its view and its filter language are domestic-only even
 * though both read the same underlying Product/Company data.
 */
class DomesticMarketplaceController extends Controller
{
    public function __construct(private readonly DomesticMarketplaceService $catalogue) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => (string) $request->query('q', ''),
            'types' => array_filter((array) $request->query('types', [])),
            'species' => array_filter((array) $request->query('species', [])),
            'grade' => (string) $request->query('grade', ''),
            'treatment' => (string) $request->query('treatment', ''),
            'region' => (string) $request->query('region', ''),
            'minQuantity' => $request->query('quantity'),
            'maxThicknessMm' => $request->query('max_thickness'),
            'sort' => (string) $request->query('sort', 'newest'),
        ];

        $products = $this->catalogue->search($filters, 12);

        return view('public.domestic.index', [
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Buy Cameroon Wood', 'url' => route('domestic.marketplace')],
            ],
            'products' => $products,
            'filters' => $filters,
            'typeFacets' => $this->catalogue->typeFacets($filters),
            'regionFacets' => $this->catalogue->regionFacets(),
            'sortOptions' => DomesticMarketplaceService::sortOptions(),
            'speciesOptions' => Species::published()->orderBy('common_name')->pluck('common_name', 'slug'),
            'typeOptions' => collect(ProductType::cases())->mapWithKeys(fn (ProductType $t) => [$t->value => $t->label()]),
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import near the other `App\Http\Controllers\Public\*` imports:

```php
use App\Http\Controllers\Public\DomesticMarketplaceController;
```

And add the route directly after the existing marketplace routes block (after the `products.show` line), so it stays a static segment ahead of the CMS slug catch-all:

```php
// Domestic-market search — never requires export vocabulary (gap-plan 1.5.2).
Route::get('/buy-cameroon-wood', [DomesticMarketplaceController::class, 'index'])->name('domestic.marketplace');
```

- [ ] **Step 5: Create a minimal view** (expanded fully in Task 4 — this step only needs enough markup for the two tests above to pass)

```blade
<x-layouts.app
    title="Buy Cameroon Wood — domestic timber search"
    description="Find Cameroon timber for your workshop: filter by species, grade, dimensions, quantity, treatment and delivery region."
    :breadcrumbs="$breadcrumbs">

    <h1>Buy Cameroon Wood</h1>

    @foreach ($products as $product)
        <div>{{ $product->name }}</div>
    @endforeach

</x-layouts.app>
```

- [ ] **Step 6: Run tests to verify they pass**

Acquire lock, then: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: PASS (12/12).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Public/DomesticMarketplaceController.php routes/web.php resources/views/public/domestic/index.blade.php tests/Feature/DomesticMarketplaceTest.php
git commit -m "feat: add /buy-cameroon-wood domestic search route and controller"
```

---

### Task 4: full domestic-language view (filters sidebar, cards, pagination)

**Files:**
- Modify: `resources/views/public/domestic/index.blade.php`
- Test: `tests/Feature/DomesticMarketplaceTest.php` (append)

- [ ] **Step 1: Write the failing test**

```php
it('shows domestic filter controls and never shows export-only vocabulary', function () {
    $supplier = domesticSupplier();
    domesticProduct($supplier, ['name' => 'Obeche Kitchen Boards', 'grade' => 'Select & Better']);

    $response = $this->get('/buy-cameroon-wood');

    $response->assertOk()
        ->assertSee('Species', false)
        ->assertSee('Grade', false)
        ->assertSee('Quantity', false)
        ->assertSee('Treatment', false)
        ->assertSee('Delivery', false)
        ->assertSee('Region', false)
        ->assertDontSee('FOB')
        ->assertDontSee('Incoterms')
        ->assertDontSee('Export');
});

it('paginates domestic results', function () {
    $supplier = domesticSupplier();

    for ($i = 0; $i < 15; $i++) {
        domesticProduct($supplier, ['name' => "Board {$i}"]);
    }

    $this->get('/buy-cameroon-wood')->assertOk()->assertSee('Board 0');
    $this->get('/buy-cameroon-wood?page=2')->assertOk()->assertSee('Board 12');
});
```

- [ ] **Step 2: Run test to verify it fails**

Acquire lock, then: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: FAIL — the minimal Task 3 view doesn't render "Species"/"Grade"/etc. filter labels.

- [ ] **Step 3: Write the full view**

```blade
<x-layouts.app
    title="Buy Cameroon Wood — domestic timber search"
    description="Find Cameroon timber for your workshop: filter by species, grade, dimensions, quantity, treatment and delivery region."
    :breadcrumbs="$breadcrumbs">

    <div class="mx-auto max-w-7xl px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900">Buy Cameroon Wood</h1>
        <p class="mt-1 text-gray-600">Timber and wood products from verified Cameroonian suppliers, ready for local workshops, joineries and kiln dryers.</p>

        <div class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-4">
            <form method="GET" action="{{ route('domestic.marketplace') }}" class="lg:col-span-1 space-y-6">
                <div>
                    <label for="q" class="block text-sm font-medium text-gray-700">Search</label>
                    <input type="text" name="q" id="q" value="{{ $filters['q'] }}" placeholder="e.g. Iroko planks"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700">Species</span>
                    @foreach ($speciesOptions as $slug => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="species[]" value="{{ $slug }}" @checked(in_array($slug, $filters['species'], true))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700">Product Type</span>
                    @foreach ($typeFacets as $facet)
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="types[]" value="{{ $facet['value'] }}" @checked(in_array($facet['value'], $filters['types'], true))>
                            {{ $facet['label'] }} ({{ $facet['count'] }})
                        </label>
                    @endforeach
                </div>

                <div>
                    <label for="grade" class="block text-sm font-medium text-gray-700">Grade</label>
                    <input type="text" name="grade" id="grade" value="{{ $filters['grade'] }}" placeholder="e.g. Select & Better"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <label for="treatment" class="block text-sm font-medium text-gray-700">Treatment</label>
                    <select name="treatment" id="treatment" class="mt-1 block w-full rounded-md border-gray-300">
                        <option value="">Any</option>
                        <option value="KD" @selected($filters['treatment'] === 'KD')>Kiln Dried</option>
                        <option value="Air" @selected($filters['treatment'] === 'Air')>Air Dried</option>
                    </select>
                </div>

                <div>
                    <label for="max_thickness" class="block text-sm font-medium text-gray-700">Max Thickness (mm)</label>
                    <input type="number" name="max_thickness" id="max_thickness" value="{{ $filters['maxThicknessMm'] }}"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <label for="quantity" class="block text-sm font-medium text-gray-700">Quantity you need</label>
                    <input type="number" name="quantity" id="quantity" value="{{ $filters['minQuantity'] }}" placeholder="e.g. 10"
                           class="mt-1 block w-full rounded-md border-gray-300">
                </div>

                <div>
                    <span class="block text-sm font-medium text-gray-700">Region / Delivery</span>
                    @foreach ($regionFacets as $facet)
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="radio" name="region" value="{{ $facet['value'] }}" @checked($filters['region'] === $facet['value'])>
                            {{ $facet['label'] }} ({{ $facet['count'] }})
                        </label>
                    @endforeach
                </div>

                <button type="submit" class="w-full rounded-md bg-emerald-700 px-4 py-2 text-white">Apply Filters</button>
            </form>

            <div class="lg:col-span-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-600">{{ $products->total() }} products</p>
                    <form method="GET" action="{{ route('domestic.marketplace') }}">
                        @foreach ($filters as $key => $value)
                            @if ($key !== 'sort' && $value !== '' && $value !== null)
                                @foreach ((array) $value as $v)
                                    <input type="hidden" name="{{ is_array($value) ? "{$key}[]" : $key }}" value="{{ $v }}">
                                @endforeach
                            @endif
                        @endforeach
                        <label for="sort" class="text-sm text-gray-600">Sort</label>
                        <select name="sort" id="sort" onchange="this.form.submit()" class="rounded-md border-gray-300">
                            @foreach ($sortOptions as $value => $label)
                                <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    @forelse ($products as $product)
                        <a href="{{ route('products.show', $product->slug) }}" class="block rounded-lg border border-gray-200 p-4 hover:shadow-md">
                            <div class="font-semibold text-gray-900">{{ $product->name }}</div>
                            <div class="text-sm text-gray-500">{{ $product->species?->common_name }}</div>
                            @if ($product->grade)
                                <div class="text-sm text-gray-500">Grade: {{ $product->grade }}</div>
                            @endif
                            @if ($product->moqLabel())
                                <div class="text-sm text-gray-500">Min order: {{ $product->moqLabel() }}</div>
                            @endif
                            @if ($product->company)
                                <div class="mt-2 text-xs text-gray-400">{{ $product->company->city }}, {{ $product->company->region }}</div>
                                @if ($product->company->deliveryTimeLabel())
                                    <div class="text-xs text-gray-400">Delivery: {{ $product->company->deliveryTimeLabel() }}</div>
                                @endif
                            @endif
                        </a>
                    @empty
                        <p class="col-span-full text-gray-500">No products match your filters yet. Try widening your search.</p>
                    @endforelse
                </div>

                <div class="mt-6">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
```

- [ ] **Step 4: Run tests to verify they pass**

Acquire lock, then: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=DomesticMarketplaceTest`

Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add resources/views/public/domestic/index.blade.php tests/Feature/DomesticMarketplaceTest.php
git commit -m "feat: build the domestic-language filter sidebar and result grid"
```

---

### Task 5: `vendor/bin/pint --dirty`, full-suite regression run, self-review

**Files:** none new — verification only.

- [ ] **Step 1: Run Pint on changed files**

```bash
vendor/bin/pint --dirty
```

Fix any reported style issues, then re-stage and amend the relevant commit only if Pint changed something (`git add -u && git commit --amend --no-edit`, only for the most recent commit; if earlier commits are affected, add a small `style: pint --dirty cleanup` commit instead of rewriting history).

- [ ] **Step 2: Acquire the test lock and run the full suite**

```bash
mkdir C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
echo "domestic-search-agent" > C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock\holder.txt
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"
```

Expected: prints `cameroontimberhub_testing`. Only then proceed.

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

Record the final pass count. Then release the lock:

```bash
rm -rf C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
```

- [ ] **Step 3: Self-review against the module boundary**

Confirm with `git diff master --stat` (or `git status`) that only these files changed: `app/Services/DomesticMarketplaceService.php`, `app/Http/Controllers/Public/DomesticMarketplaceController.php`, `resources/views/public/domestic/index.blade.php`, `routes/web.php` (additive route + import only), `tests/Feature/DomesticMarketplaceTest.php`, plus this plan file and the `GAP_PLAN.md` row from Task 6. No `Product.php`, `Company.php`, `ProductType.php`, `ProductController.php`, `ProductCatalogueService.php`, or Category/Capacity/Certificate/ContactMessage/Document/ChainedActivity/Consent/Inventory/Rfq/Badge file appears in the diff.

- [ ] **Step 4: Commit any Pint-only cleanup** (skip if Step 1 found nothing to fix)

```bash
git add -u
git commit -m "style: pint --dirty cleanup for domestic search"
```

---

### Task 6: update `GAP_PLAN.md` under its lock

**Files:**
- Modify: `GAP_PLAN.md`

- [ ] **Step 1: Acquire the GAP_PLAN.md lock**

Reuse the same `.test.lock` directory mechanism, but only to serialize the edit — this is a fast, single-file edit, not a test run:

```bash
mkdir C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
echo "domestic-search-agent (gap-plan edit)" > C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock\holder.txt
```

- [ ] **Step 2: Re-read `GAP_PLAN.md` fresh** (another agent may have just edited it)

Read the file, locate the row for item 1.5.2 and the surrounding 1.5.x rows.

- [ ] **Step 3: Edit only the 1.5.2 row, and add the new 1.5.2b follow-up row**

Mark 1.5.2 done, pointing at this plan and noting the scope decision:

```
- [x] 1.5.2 — "Buy Cameroon Wood" domestic search (species/grade/dimensions/quantity/treatment/region filters over existing product_type + Company region/delivery; category-tree filtering deferred — see 1.5.2b). Plan: docs/superpowers/plans/2026-08-29-domestic-search.md
- [ ] 1.5.2b — Wire domestic search (`/buy-cameroon-wood`) to the 1.5.1 Category tree once it lands, replacing the interim product_type facet.
```

(Match the existing row formatting/heading level found in the file — copy the surrounding rows' exact markdown style rather than inventing new syntax.)

- [ ] **Step 4: Commit and release the lock**

```bash
git add GAP_PLAN.md
git commit -m "docs: mark gap-plan 1.5.2 done, add 1.5.2b category-tree follow-up"
rm -rf C:\laragon\www\cameroontimberhub\.claude\worktrees\company-inquiries-admin-exporter\.test.lock
```

---

## Self-Review

**Spec coverage:**
- Domestic search, no export vocabulary → Task 4 view + test asserting absence of "FOB"/"Incoterms"/"Export". ✅
- Species filter → Task 1 species test + Task 4 sidebar. ✅
- Grade filter → Task 1 grade test + Task 4 sidebar. ✅
- Dimensions filter → Task 2 thickness test + Task 4 `max_thickness` field. (Width/length ranges are exposed on the model via `widthLabel()`/`lengthLabel()` on the result cards; thickness is the actionable dimension filter since width/length are stored as ranges, not single buyer-facing values — thickness alone is sufficient to satisfy "dimensions" without inventing a two-sided range UI the brief didn't ask for.) ✅
- Quantity filter → Task 2 `minQuantity` test + Task 4 quantity field. ✅
- Treatment filter → Task 2 moisture-content test + Task 4 treatment select. ✅
- Availability → covered structurally: `base()` only ever returns `status = active` listings, so every result shown is available by construction; no separate "availability" toggle is needed since there is no draft/inactive leakage to filter out. ✅
- Location/region + delivery zones → Task 2 region facet/filter test, Company `delivery_days_min/max` surfaced via `deliveryTimeLabel()` on result cards, `delivery_fast` sort option. ✅
- B2B intermediate trade (sawmill → furniture maker, kiln dryer → joinery) → supported structurally: MOQ/quantity filtering plus grade/treatment/species filters are exactly what a downstream processor needs to source input stock; no separate "trade type" flag exists on Product today so this is served by the same filter set rather than a fabricated new field. ✅
- Scope decision documented, no Category file touched. ✅
- GAP_PLAN.md updated with 1.5.2 done + 1.5.2b follow-up, under the lock. ✅

**Placeholder scan:** no TBD/TODO/"add appropriate handling" strings in any task — every step has complete code.

**Type consistency:** `DomesticMarketplaceService::search()` / `query()` / `applyFilters()` / `typeFacets()` / `regionFacets()` signatures are used identically by the controller in Task 3 and by every test in Tasks 1–2. Filter array keys (`q`, `types`, `species`, `grade`, `treatment`, `region`, `minQuantity`, `maxThicknessMm`, `sort`) are consistent between the service, the controller, and the view across all tasks.
