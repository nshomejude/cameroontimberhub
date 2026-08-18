<?php

use App\Enums\CompanyStatus;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Support\Facades\DB;

/**
 * A supplier that satisfies every Company::scopePubliclyVisible() predicate —
 * verified status, logo, description, region, plus species, a public contact
 * and an active verification badge.
 */
function searchSupplier(array $attributes = []): Company
{
    return Company::factory()->publiclyVisible()->create(array_merge([
        'legal_name' => 'Kuete Forest Company',
        'city' => 'Yaoundé',
        'region' => 'Centre',
    ], $attributes));
}

function searchProduct(Company $company, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'company_id' => $company->id,
        'name' => 'Iroko Sawn Timber KD 50mm',
        'status' => ProductStatus::Active,
    ], $attributes));
}

it('renders the no-query state without error', function () {
    $this->get('/search')
        ->assertOk()
        ->assertSee('Start your search')
        ->assertSee('Browse marketplace');
});

it('marks search result pages noindex', function () {
    $this->get('/search?q=iroko')
        ->assertOk()
        ->assertSee('noindex, follow', escape: false);
});

it('finds products, suppliers and species for a query', function () {
    $company = searchSupplier();
    searchProduct($company);
    Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-test', 'is_published' => true]);

    $this->get('/search?q=Iroko&type=products')->assertOk()->assertSee('Iroko Sawn Timber KD 50mm');
    $this->get('/search?q=Kuete&type=suppliers')->assertOk()->assertSee('Kuete Forest Company');
    $this->get('/search?q=Iroko&type=species')->assertOk()->assertSee('Iroko');
});

it('never leaks draft products into search', function () {
    $company = searchSupplier();
    searchProduct($company, ['name' => 'Secret Draft Iroko Batch', 'status' => ProductStatus::Draft]);

    $this->get('/search?q=Iroko&type=products')
        ->assertOk()
        ->assertDontSee('Secret Draft Iroko Batch');
});

it('never leaks products belonging to a non-visible company', function () {
    $hidden = Company::factory()->create([
        'legal_name' => 'Hidden Timber Ltd',
        'status' => CompanyStatus::Draft,
        'verified_at' => null,
    ]);
    searchProduct($hidden, ['name' => 'Hidden Company Iroko Board']);

    $this->get('/search?q=Iroko&type=products')
        ->assertOk()
        ->assertDontSee('Hidden Company Iroko Board');
});

it('never leaks unverified companies into supplier search', function () {
    Company::factory()->create([
        'legal_name' => 'Unverified Timber Traders',
        'status' => CompanyStatus::Draft,
        'verified_at' => null,
    ]);

    $this->get('/search?q=Timber&type=suppliers')
        ->assertOk()
        ->assertDontSee('Unverified Timber Traders');
});

it('never leaks unpublished species into species search', function () {
    Species::factory()->create([
        'common_name' => 'Unpublished Mystery Wood',
        'slug' => 'unpublished-mystery',
        'is_published' => false,
    ]);

    $this->get('/search?q=Mystery&type=species')
        ->assertOk()
        ->assertDontSee('Unpublished Mystery Wood');
});

it('matches a misspelled query through trigram similarity', function () {
    Species::factory()->create(['common_name' => 'Sapelli', 'slug' => 'sapelli-test', 'is_published' => true]);

    // "sapeli" is not an FTS match for "Sapelli"; only trigram similarity finds it.
    $this->get('/search?q=sapeli&type=species')
        ->assertOk()
        ->assertSee('Sapelli');
});

it('narrows product results by species and product type facets', function () {
    $company = searchSupplier();
    $iroko = Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-facet', 'is_published' => true]);
    $sapelli = Species::factory()->create(['common_name' => 'Sapelli', 'slug' => 'sapelli-facet', 'is_published' => true]);

    searchProduct($company, ['name' => 'Iroko Beam Alpha', 'species_id' => $iroko->id]);
    searchProduct($company, ['name' => 'Sapelli Beam Beta', 'species_id' => $sapelli->id]);

    $response = $this->get('/search?q=Beam&type=products&species=iroko-facet')->assertOk();
    $response->assertSee('Iroko Beam Alpha');
    $response->assertDontSee('Sapelli Beam Beta');
});

it('round-trips filter state through the url', function () {
    $company = searchSupplier();
    searchProduct($company);

    $this->get('/search?q=Iroko&type=products&sort=price_asc')
        ->assertOk()
        // The sort select keeps its value, so the URL is shareable.
        ->assertSee('value="price_asc" selected', escape: false);
});

it('offers species suggestions when a query returns nothing', function () {
    Species::factory()->create(['common_name' => 'Sapelli', 'slug' => 'sapelli-suggest', 'is_published' => true]);

    $this->get('/search?q=Sapeli&type=products')
        ->assertOk()
        ->assertSee('Did you mean')
        ->assertSee('Sapelli');
});

it('exposes breadcrumb structured data', function () {
    $this->get('/search?q=iroko')
        ->assertOk()
        ->assertSee('BreadcrumbList', escape: false);
});

it('does not issue a query per result row', function () {
    $company = searchSupplier();

    for ($i = 0; $i < 8; $i++) {
        searchProduct($company, ['name' => "Iroko Plank Number {$i}", 'slug' => "iroko-plank-{$i}"]);
    }

    DB::enableQueryLog();
    $this->get('/search?q=Iroko&type=products')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Eager loading keeps this bounded; without it, 8 products add 8+ queries.
    expect($count)->toBeLessThan(25);
});
