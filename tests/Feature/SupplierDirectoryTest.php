<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\SupplierType;
use App\Livewire\CompanyDirectory;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\SpeciesSeeder;
use Livewire\Livewire;

/** A publicly visible supplier with directory metrics set. */
function supplier(array $attrs = []): Company
{
    return Company::factory()->publiclyVisible()->create($attrs);
}

// ---- Page ---------------------------------------------------------------

it('renders the supplier directory page', function () {
    supplier(['legal_name' => 'Visible Directory Co']);

    $this->get(route('directory'))
        ->assertOk()
        ->assertSee('Supplier Directory')
        ->assertSee('Visible Directory Co')
        ->assertSee('Grid View')
        ->assertSee('Apply Filters');
});

it('shows only publicly visible companies', function () {
    supplier(['legal_name' => 'Verified Listed Co']);
    Company::factory()->create(['legal_name' => 'Draft Hidden Co']);

    Livewire::test(CompanyDirectory::class)
        ->assertSee('Verified Listed Co')
        ->assertDontSee('Draft Hidden Co');
});

it('paginates results at the selected page size', function () {
    Company::factory()->publiclyVisible()->count(15)->create();

    Livewire::test(CompanyDirectory::class)
        ->assertViewHas('companies', fn ($p) => $p->total() === 15 && $p->count() === 12)
        ->set('perPage', 24)
        ->assertViewHas('companies', fn ($p) => $p->count() === 15);
});

// ---- Filters ------------------------------------------------------------

it('narrows results by supplier type', function () {
    supplier(['legal_name' => 'Type Match Co', 'supplier_type' => SupplierType::Trader]);
    supplier(['legal_name' => 'Type Other Co', 'supplier_type' => SupplierType::Manufacturer]);

    Livewire::test(CompanyDirectory::class)
        ->set('types', [SupplierType::Trader->value])
        ->assertSee('Type Match Co')
        ->assertDontSee('Type Other Co');
});

it('narrows results by species', function () {
    $species = Species::factory()->create(['common_name' => 'Filterwood', 'slug' => 'filterwood']);

    $match = supplier(['legal_name' => 'Species Match Co']);
    $match->species()->attach($species);

    supplier(['legal_name' => 'Species Other Co']);

    Livewire::test(CompanyDirectory::class)
        ->set('speciesIn', ['filterwood'])
        ->assertSee('Species Match Co')
        ->assertDontSee('Species Other Co');
});

it('narrows results by product specialisation', function () {
    $match = supplier(['legal_name' => 'Spec Match Co']);
    Product::factory()->create([
        'company_id' => $match->id,
        'product_type' => ProductType::Plywood,
        'status' => ProductStatus::Active,
    ]);

    supplier(['legal_name' => 'Spec Other Co']);

    Livewire::test(CompanyDirectory::class)
        ->set('specs', [ProductType::Plywood->value])
        ->assertSee('Spec Match Co')
        ->assertDontSee('Spec Other Co');
});

it('narrows results by free-text search', function () {
    supplier(['legal_name' => 'Zanzibar Hardwoods Sarl']);
    supplier(['legal_name' => 'Unrelated Trading Sarl']);

    Livewire::test(CompanyDirectory::class)
        ->set('search', 'Zanzibar')
        ->assertSee('Zanzibar Hardwoods Sarl')
        ->assertDontSee('Unrelated Trading Sarl');
});

it('narrows results by region', function () {
    supplier(['legal_name' => 'Adamawa Region Co', 'region' => 'Adamawa']);
    supplier(['legal_name' => 'Littoral Region Co', 'region' => 'Littoral']);

    Livewire::test(CompanyDirectory::class)
        ->set('region', 'Adamawa')
        ->assertSee('Adamawa Region Co')
        ->assertDontSee('Littoral Region Co');
});

it('ANDs combined filters together', function () {
    supplier(['legal_name' => 'Both Criteria Co', 'region' => 'Adamawa', 'supplier_type' => SupplierType::Trader]);
    supplier(['legal_name' => 'Region Only Co', 'region' => 'Adamawa', 'supplier_type' => SupplierType::Exporter]);
    supplier(['legal_name' => 'Type Only Co', 'region' => 'Littoral', 'supplier_type' => SupplierType::Trader]);

    Livewire::test(CompanyDirectory::class)
        ->set('region', 'Adamawa')
        ->set('types', [SupplierType::Trader->value])
        ->assertSee('Both Criteria Co')
        ->assertDontSee('Region Only Co')
        ->assertDontSee('Type Only Co');
});

it('reports real counts beside the supplier type facet', function () {
    supplier(['supplier_type' => SupplierType::Trader]);
    supplier(['supplier_type' => SupplierType::Trader]);
    supplier(['supplier_type' => SupplierType::Exporter]);

    Livewire::test(CompanyDirectory::class)->assertViewHas('typeFacets', function (array $facets): bool {
        $byValue = collect($facets)->keyBy('value');

        return $byValue[SupplierType::Trader->value]['count'] === 2
            && $byValue[SupplierType::Exporter->value]['count'] === 1
            && $byValue[SupplierType::LogisticsProvider->value]['count'] === 0;
    });
});

// ---- URL state ----------------------------------------------------------

it('round-trips filter state through the URL', function () {
    supplier(['legal_name' => 'Url State Co', 'region' => 'Adamawa', 'supplier_type' => SupplierType::Trader]);
    supplier(['legal_name' => 'Url Hidden Co', 'region' => 'Littoral', 'supplier_type' => SupplierType::Exporter]);

    // Writing: setting filters pushes them into the query string.
    Livewire::withQueryParams([])
        ->test(CompanyDirectory::class)
        ->set('types', [SupplierType::Trader->value])
        ->set('region', 'Adamawa')
        ->set('sort', 'name')
        ->assertSee('Url State Co');

    // Reading: a shared URL reconstructs exactly the same view after a refresh.
    Livewire::withQueryParams([
        'type' => [SupplierType::Trader->value],
        'region' => 'Adamawa',
        'sort' => 'name',
        'view' => 'list',
    ])
        ->test(CompanyDirectory::class)
        ->assertSet('types', [SupplierType::Trader->value])
        ->assertSet('region', 'Adamawa')
        ->assertSet('sort', 'name')
        ->assertSet('view', 'list')
        ->assertSee('Url State Co')
        ->assertDontSee('Url Hidden Co');
});

it('serves a filtered directory URL as a full page request', function () {
    supplier(['legal_name' => 'Deep Link Co', 'supplier_type' => SupplierType::Trader]);
    supplier(['legal_name' => 'Deep Link Hidden Co', 'supplier_type' => SupplierType::Exporter]);

    $this->get(route('directory', ['type' => [SupplierType::Trader->value]]))
        ->assertOk()
        ->assertSee('Deep Link Co')
        ->assertDontSee('Deep Link Hidden Co');
});

// ---- Columns ------------------------------------------------------------

it('casts the new company columns', function () {
    $company = supplier([
        'supplier_type' => SupplierType::LogisticsProvider,
        'response_rate_percent' => 87,
        'years_experience' => 9,
    ])->fresh();

    expect($company->supplier_type)->toBe(SupplierType::LogisticsProvider)
        ->and($company->response_rate_percent)->toBe(87)
        ->and($company->years_experience)->toBe(9);
});

it('renders real metrics and omits null ones', function () {
    supplier([
        'legal_name' => 'Metric Rich Co',
        'response_rate_percent' => 87,
        'years_experience' => 9,
    ]);

    Livewire::test(CompanyDirectory::class)
        ->assertSee('87%')
        ->assertSee('9 Yrs');
});

it('does not render a stat strip for a company with no metrics', function () {
    supplier(['legal_name' => 'Metric Free Co', 'response_rate_percent' => null, 'years_experience' => null]);

    Livewire::test(CompanyDirectory::class)
        ->assertSee('Metric Free Co')
        ->assertDontSee('Response Rate')
        ->assertDontSee('Yrs');
});

it('hides the response-rate stat tile when no company has a rate', function () {
    supplier(['response_rate_percent' => null]);

    Livewire::test(CompanyDirectory::class)->assertViewHas('stats', function (array $stats): bool {
        return collect($stats)->doesntContain(fn (array $t) => $t['label'] === 'Response Rate');
    });
});

// ---- View toggle --------------------------------------------------------

it('switches between grid and list layouts', function () {
    supplier(['legal_name' => 'Layout Toggle Co']);

    Livewire::test(CompanyDirectory::class)
        ->assertSet('view', 'grid')
        ->call('setView', 'list')
        ->assertSet('view', 'list')
        ->call('setView', 'nonsense')
        ->assertSet('view', 'grid');
});

// ---- Structured data ----------------------------------------------------

it('emits valid BreadcrumbList and ItemList JSON-LD', function () {
    $company = supplier(['legal_name' => 'Schema Listed Co']);

    $html = $this->get(route('directory'))->assertOk()->getContent();

    expect($html)->toContain('"@type":"BreadcrumbList"')
        ->and($html)->toContain('"@type":"ItemList"');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    $graphs = collect($matches[1])->map(fn (string $json) => json_decode($json, true));

    expect($graphs)->each->not->toBeNull();

    $itemList = $graphs->firstWhere('@type', 'ItemList');
    expect($itemList)->not->toBeNull()
        ->and($itemList['itemListElement'][0]['@type'])->toBe('ListItem')
        ->and($itemList['itemListElement'][0]['position'])->toBe(1)
        ->and($itemList['itemListElement'][0]['url'])->toBe(route('companies.show', $company->slug))
        ->and($itemList['itemListElement'][0]['item']['@type'])->toBe('Organization');

    $breadcrumbs = $graphs->firstWhere('@type', 'BreadcrumbList');
    expect($breadcrumbs['itemListElement'])->toHaveCount(2)
        ->and($breadcrumbs['itemListElement'][1]['name'])->toBe('Suppliers');
});

// ---- Seeder -------------------------------------------------------------

it('seeds mockup suppliers idempotently', function () {
    $this->seed(SpeciesSeeder::class);

    $this->seed(DemoCompanySeeder::class);
    $first = Company::withTrashed()->count();

    $this->seed(DemoCompanySeeder::class);

    expect(Company::withTrashed()->count())->toBe($first);

    $awe = Company::where('slug', 'african-wood-exporters')->firstOrFail();

    expect($awe->supplier_type)->toBe(SupplierType::Exporter)
        ->and($awe->response_rate_percent)->toBe(98)
        ->and($awe->years_experience)->toBe(10)
        ->and($awe->logo_path)->toBe('suppliers/logos/african-wood-exporters.png');
})->group('seeder');
