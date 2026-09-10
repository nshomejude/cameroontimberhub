<?php

use App\Enums\CompanyStatus;
use App\Enums\ProductType;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue endpoints are public, so the visibility gate is the entire
 * security boundary here: draft listings, listings from a company that is not
 * publicly visible, and unpublished species must be invisible on every single
 * listing endpoint — not merely on the ones somebody remembered to check.
 */

/** A publicly visible supplier with one active listing, plus three things that must stay hidden. */
function catalogueFixture(): array
{
    $visibleCompany = Company::factory()->publiclyVisible()->create(['legal_name' => 'Visible Timber Sarl', 'region' => 'Littoral']);
    $hiddenCompany = Company::factory()->create(['legal_name' => 'Unverified Timber Sarl', 'status' => CompanyStatus::Pending]);

    $species = Species::factory()->create(['common_name' => 'Visiblewood']);
    $secretSpecies = Species::factory()->unpublished()->create(['common_name' => 'Secretwood']);

    $visible = Product::factory()->active()->for($visibleCompany)->for($species)->create([
        'name' => 'Visiblewood Sawn Timber',
        'product_type' => ProductType::SawnTimber,
    ]);

    $draft = Product::factory()->draft()->for($visibleCompany)->for($species)->create(['name' => 'Draft Board']);
    $hidden = Product::factory()->active()->for($hiddenCompany)->for($species)->create(['name' => 'Hidden Company Board']);

    return compact('visibleCompany', 'hiddenCompany', 'species', 'secretSpecies', 'visible', 'draft', 'hidden');
}

/* ------------------------------------------------------------- products */

it('lists only active products from publicly visible suppliers', function () {
    $f = catalogueFixture();

    $response = $this->getJson('/api/v1/products')->assertOk();

    expect(collect($response->json('data'))->pluck('slug')->all())
        ->toBe([$f['visible']->slug])
        ->and(json_encode($response->json()))
        ->not->toContain('Draft Board')
        ->not->toContain('Hidden Company Board');
});

it('returns pagination meta and the catalogue facets', function () {
    catalogueFixture();

    $this->getJson('/api/v1/products?per_page=5')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'slug', 'name', 'price', 'moq', 'supplier']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total', 'facets' => ['types', 'species', 'regions', 'flags'], 'sort_options'],
        ])
        ->assertJsonPath('meta.per_page', 5);
});

it('filters products by species, type, region and free text', function () {
    $f = catalogueFixture();

    $this->getJson('/api/v1/products?species[]='.$f['species']->slug)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?species[]=no-such-species')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?types[]=sawn_timber')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?region=Littoral')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?region=Adamawa')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?q=Visiblewood')->assertOk()->assertJsonCount(1, 'data');
});

it('422s an unknown sort or an oversized page', function () {
    $this->getJson('/api/v1/products?sort=cheapest-ever')->assertStatus(422)->assertJsonValidationErrors('sort', 'error.details');
    $this->getJson('/api/v1/products?per_page=5000')->assertStatus(422)->assertJsonValidationErrors('per_page', 'error.details');
    $this->getJson('/api/v1/products?types[]=nonsense')->assertStatus(422);
});

it('returns product detail with specifications, gallery, species and supplier', function () {
    $f = catalogueFixture();

    $this->getJson('/api/v1/products/'.$f['visible']->slug)
        ->assertOk()
        ->assertJsonPath('data.slug', $f['visible']->slug)
        ->assertJsonPath('data.supplier.slug', $f['visibleCompany']->slug)
        ->assertJsonPath('data.species_detail.common_name', 'Visiblewood')
        ->assertJsonStructure(['data' => ['specifications', 'gallery', 'trust_badges', 'price', 'supplier' => ['badges', 'contacts']]]);
});

it('404s a draft product and a product behind a hidden supplier', function () {
    $f = catalogueFixture();

    $this->getJson('/api/v1/products/'.$f['draft']->slug)->assertNotFound();
    $this->getJson('/api/v1/products/'.$f['hidden']->slug)->assertNotFound();
});

it('holds product listing queries to a bounded count regardless of page size', function () {
    $company = Company::factory()->publiclyVisible()->create();
    Product::factory()->count(12)->active()->for($company)->create();

    DB::enableQueryLog();
    $this->getJson('/api/v1/products?per_page=12')->assertOk()->assertJsonCount(12, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Eager-loaded: no per-row supplier or species lookup.
    expect($queries)->toBeLessThan(25);
});

/**
 * The embedded supplier is ONE contract.
 *
 * The list used to eager-load a narrower column set than the detail query, so
 * `verified_at`, `rating`, `supplier_type` and `country_code` came back null on
 * a card and populated on the detail page — same key, two meanings, and a
 * marketplace card that could not render a verified badge. Both now select
 * Company::CARD_COLUMNS, so this compares field by field rather than merely
 * asserting the keys exist.
 */
it('embeds an identical, fully populated supplier in the product list and the product detail', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'Bounded Timber Sarl',
        'trade_name' => 'Bounded Timber',
        'city' => 'Douala',
        'region' => 'Littoral',
        'country_code' => 'CM',
        'supplier_type' => SupplierType::Manufacturer,
        'years_experience' => 14,
        'response_rate_percent' => 92,
        'orders_completed' => 37,
        'rating_avg' => 4.5,
        'rating_count' => 12,
        'is_featured' => true,
    ]);

    $product = Product::factory()->active()->for($company)->create(['name' => 'Bounded Board']);

    $listSupplier = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))
        ->firstWhere('slug', $product->slug)['supplier'];

    $detailSupplier = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->json('data.supplier');

    // The detail resource extends the card, so every card key must be present
    // on the detail and carry exactly the same value.
    expect(array_keys($listSupplier))->not->toBeEmpty();

    foreach ($listSupplier as $key => $value) {
        expect($detailSupplier)->toHaveKey($key);
        expect($detailSupplier[$key])->toEqual($value, "supplier.{$key} differs between list and detail");
    }

    // And the fields that were silently null on the card are really populated —
    // an assertion of equality alone would pass if both sides were null.
    expect($listSupplier['verified_at'])->not->toBeNull()
        ->and($listSupplier['country_code'])->toBe('CM')
        ->and($listSupplier['supplier_type'])->toBe(SupplierType::Manufacturer->value)
        ->and($listSupplier['is_featured'])->toBeTrue()
        ->and($listSupplier['years_experience'])->toBe(14)
        ->and($listSupplier['response_rate_percent'])->toBe(92)
        ->and($listSupplier['orders_completed'])->toBe(37)
        ->and($listSupplier['rating'])->toBe(['average' => 4.5, 'count' => 12])
        ->and($listSupplier['name'])->toBe('Bounded Timber');
});

/**
 * The wider select must come from the existing eager load, not from a per-row
 * lookup — widening the columns is only a fix if it stays one query.
 */
it('keeps the product listing query count bounded with the full supplier card columns', function () {
    $company = Company::factory()->publiclyVisible()->create(['supplier_type' => SupplierType::Manufacturer]);
    Product::factory()->count(12)->active()->for($company)->create();

    DB::enableQueryLog();
    $response = $this->getJson('/api/v1/products?per_page=12')->assertOk()->assertJsonCount(12, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Every card is fully rendered...
    foreach ($response->json('data') as $row) {
        expect($row['supplier']['verified_at'])->not->toBeNull()
            ->and($row['supplier']['supplier_type'])->toBe(SupplierType::Manufacturer->value)
            ->and($row['supplier']['country_code'])->not->toBeNull();
    }

    // ...without one supplier query per row.
    expect($queries)->toBeLessThan(25);
});

it('embeds the same supplier card shape in search results', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'Searchable Timber Sarl',
        'supplier_type' => SupplierType::Manufacturer,
    ]);
    Product::factory()->active()->for($company)->create(['name' => 'Searchable Sapele Board']);

    $supplier = $this->getJson('/api/v1/search?q=Searchable&type=products')
        ->assertOk()->json('data.0.supplier');

    expect($supplier['verified_at'])->not->toBeNull()
        ->and($supplier['supplier_type'])->toBe(SupplierType::Manufacturer->value)
        ->and($supplier['country_code'])->toBe('CM');
});

/* -------------------------------------------------------------- species */

it('lists only published species and 404s an unpublished one', function () {
    $f = catalogueFixture();

    $response = $this->getJson('/api/v1/species')->assertOk();

    expect(collect($response->json('data'))->pluck('common_name')->all())
        ->toContain('Visiblewood')
        ->not->toContain('Secretwood');

    $this->getJson('/api/v1/species/'.$f['secretSpecies']->slug)->assertNotFound();
});

it('returns species detail with the Cameroon classification fields', function () {
    $species = Species::factory()->create([
        'common_name' => 'Ayous',
        'commercial_category' => 'secondary_hardwood',
        'log_export_status' => 'banned',
        'is_promoted' => true,
        'density_kg_m3_min' => 350,
        'density_kg_m3_max' => 500,
        'durability_class' => 'Class 4 (slightly durable)',
        'typical_uses' => ['Plywood', 'Joinery'],
        'region_availability' => ['East', 'South'],
    ]);

    $this->getJson('/api/v1/species/'.$species->slug)
        ->assertOk()
        ->assertJsonPath('data.commercial_category', 'secondary_hardwood')
        ->assertJsonPath('data.log_export_status', 'banned')
        ->assertJsonPath('data.is_promoted', true)
        ->assertJsonPath('data.density_range', '350–500 kg/m³')
        ->assertJsonPath('data.typical_uses', ['Plywood', 'Joinery'])
        ->assertJsonPath('data.region_availability', ['East', 'South'])
        // The regulatory caveat travels with the field, as it does on the web.
        ->assertJsonPath('data.log_export_status_note', 'Informational only — verify against current MINFOF publications.');
});

it('exposes the species facets in listing meta', function () {
    catalogueFixture();

    $this->getJson('/api/v1/species')
        ->assertOk()
        ->assertJsonStructure(['meta' => ['facets' => ['categories', 'properties', 'applications', 'regions'], 'sort_options']]);
});

/* ------------------------------------------------------------ suppliers */

it('lists only publicly visible suppliers and 404s the rest', function () {
    $f = catalogueFixture();

    $response = $this->getJson('/api/v1/suppliers')->assertOk();

    expect(collect($response->json('data'))->pluck('slug')->all())->toBe([$f['visibleCompany']->slug]);

    $this->getJson('/api/v1/suppliers/'.$f['hiddenCompany']->slug)->assertNotFound();
    $this->getJson('/api/v1/suppliers/'.$f['visibleCompany']->slug)->assertOk()
        ->assertJsonPath('data.slug', $f['visibleCompany']->slug)
        ->assertJsonStructure(['data' => ['name', 'logo_url', 'export_markets', 'badges', 'contacts', 'products_count']]);
});

it('never exposes a private supplier contact row', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $company->contacts()->create([
        'name' => 'Private Backoffice', 'email' => 'private@internal.test', 'is_public' => false,
    ]);

    $body = json_encode($this->getJson('/api/v1/suppliers/'.$company->slug)->assertOk()->json());

    expect($body)->not->toContain('private@internal.test')->not->toContain('Private Backoffice');
});

/* --------------------------------------------------------------- search */

it('cross-searches inside the visibility gate and reports per-bucket counts', function () {
    $f = catalogueFixture();

    $response = $this->getJson('/api/v1/search?q=Visiblewood')->assertOk();

    expect($response->json('meta.counts'))->toHaveKeys(['products', 'suppliers', 'species'])
        ->and(json_encode($response->json()))
        ->not->toContain('Draft Board')
        ->not->toContain('Hidden Company Board')
        ->not->toContain('Secretwood');
});

it('switches the search bucket and 422s an unknown one', function () {
    catalogueFixture();

    $this->getJson('/api/v1/search?q=Visible&type=suppliers')->assertOk()->assertJsonPath('meta.type', 'suppliers');
    $this->getJson('/api/v1/search?q=Visible&type=species')->assertOk()->assertJsonPath('meta.type', 'species');
    $this->getJson('/api/v1/search?q=Visible&type=orders')->assertStatus(422)->assertJsonValidationErrors('type', 'error.details');
});

it('never leaks an internal product or company column through any listing endpoint', function () {
    catalogueFixture();

    foreach (['/api/v1/products', '/api/v1/species', '/api/v1/suppliers', '/api/v1/search?q=Visible'] as $url) {
        $body = json_encode($this->getJson($url)->assertOk()->json());

        expect($body)
            ->not->toContain('search_vector')
            ->not->toContain('created_by')
            ->not->toContain('deleted_at')
            ->not->toContain('spam_score')
            ->not->toContain('ip_address');
    }
});
