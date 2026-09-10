<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Category;
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

it('filters by form category (including a category and its children)', function () {
    $supplier = domesticSupplier();
    $secondary = Category::where('kind', 'form')->where('slug', 'secondary-processed')->firstOrFail();
    $raw = Category::where('kind', 'form')->where('slug', 'raw')->firstOrFail();
    $child = Category::create(['kind' => 'form', 'slug' => 'kd-boards', 'name' => 'KD Boards', 'parent_id' => $secondary->id]);

    $match = domesticProduct($supplier, ['category_id' => $secondary->id, 'name' => 'Domestic Planks']);
    $childMatch = domesticProduct($supplier, ['category_id' => $child->id, 'name' => 'Child KD Boards']);
    domesticProduct($supplier, ['category_id' => $raw->id, 'name' => 'Round Logs']);

    $results = app(DomesticMarketplaceService::class)->search(['categories' => ['secondary-processed']]);

    expect($results->pluck('name')->sort()->values()->all())->toBe([$childMatch->name, $match->name]);
});

it('redirects legacy ?product_type= links to the category equivalent', function () {
    $res = $this->get('/buy-cameroon-wood?product_type=sawn_timber');
    $res->assertStatus(301);
    expect($res->headers->get('Location'))->toContain('categories')->toContain('secondary-processed');

    $keep = $this->get('/buy-cameroon-wood?types%5B0%5D=logs&region=Centre');
    $keep->assertStatus(301);
    expect($keep->headers->get('Location'))->toContain('raw')->toContain('region=Centre');
});

it('still resolves an old ?product_type= URL to matching listings after the redirect', function () {
    $supplier = domesticSupplier();
    domesticProduct($supplier, ['product_type' => ProductType::SawnTimber, 'name' => 'Legacy Sawn Match']);
    domesticProduct($supplier, ['product_type' => ProductType::Logs, 'name' => 'Legacy Logs Miss']);

    $this->followingRedirects()
        ->get('/buy-cameroon-wood?product_type=sawn_timber')
        ->assertOk()
        ->assertSee('Legacy Sawn Match')
        ->assertDontSee('Legacy Logs Miss');
});

it('filters by grade', function () {
    $supplier = domesticSupplier();
    $match = domesticProduct($supplier, ['grade' => 'Select & Better', 'name' => 'Graded Boards']);
    domesticProduct($supplier, ['grade' => 'Standard', 'name' => 'Standard Boards']);

    $results = app(DomesticMarketplaceService::class)->search(['grade' => 'Select & Better']);

    expect($results->pluck('name')->all())->toBe([$match->name]);
});

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

it('computes category facets that add up to the visible catalogue and drop zero counts', function () {
    $supplier = domesticSupplier();
    domesticProduct($supplier, ['product_type' => ProductType::Planks]);
    domesticProduct($supplier, ['product_type' => ProductType::Planks]);

    $facets = app(DomesticMarketplaceService::class)->categoryFacets([]);
    $secondary = collect($facets)->firstWhere('value', 'secondary-processed');

    expect($secondary['count'])->toBeGreaterThanOrEqual(2)
        ->and(collect($facets)->pluck('count')->min())->toBeGreaterThan(0);
});

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
        ->assertDontSee('Export Ready')
        ->assertDontSee('Export markets');
});

it('paginates domestic results', function () {
    $supplier = domesticSupplier();

    // Zero-padded names so the `name` sort is a deterministic numeric order
    // (the default `newest` sort has no secondary key, so which product lands
    // on which page depends on sub-millisecond insert timing).
    for ($i = 0; $i < 15; $i++) {
        domesticProduct($supplier, ['name' => sprintf('Board %02d', $i)]);
    }

    // 12 per page: page 1 = Board 00..11, page 2 = Board 12..14.
    $this->get('/buy-cameroon-wood?sort=name')->assertOk()
        ->assertSee('Board 00')->assertSee('Board 11')->assertDontSee('Board 12');
    $this->get('/buy-cameroon-wood?sort=name&page=2')->assertOk()
        ->assertSee('Board 12')->assertSee('Board 14')->assertDontSee('Board 00');
});
