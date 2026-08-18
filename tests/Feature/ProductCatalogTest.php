<?php

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('lists only active products on the marketplace', function () {
    $company = Company::factory()->publiclyVisible()->create();

    Product::factory()->active()->for($company)->create(['name' => 'Visible Iroko Board']);
    Product::factory()->for($company)->create(['name' => 'Hidden Draft Board']);       // draft
    Product::factory()->archived()->for($company)->create(['name' => 'Old Archived Board']);

    $this->get(route('marketplace'))
        ->assertOk()
        ->assertSee('Visible Iroko Board')
        ->assertDontSee('Hidden Draft Board')
        ->assertDontSee('Old Archived Board');
});

it('renders a product detail page with supplier, species, price and Product JSON-LD', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'Detail Supplier Sarl',
        'trade_name' => 'DetailCo',
    ]);
    $species = Species::factory()->create(['common_name' => 'Detailwood']);

    $product = Product::factory()->active()->for($company)->for($species)->create([
        'name' => 'Detailwood Sawn Timber KD 50mm',
        'price_amount' => 650000,
        'price_currency' => 'XAF',
        'rating' => 4.8,
        'reviews_count' => 24,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Detailwood Sawn Timber KD 50mm')
        ->assertSee('DetailCo')
        ->assertSee('Detailwood')
        ->assertSee('650,000')
        ->assertSee('"@type":"Product"', false)
        ->assertSee('"@type":"Offer"', false)
        ->assertSee('"@type":"AggregateRating"', false)
        ->assertSee('"name":"DetailCo"', false);
});

it('404s on the detail route for non-active products', function (string $state) {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->{$state}()->for($company)->create();

    $this->get(route('products.show', $product->slug))->assertNotFound();
})->with([
    'draft' => ['draft'],
    'archived' => ['archived'],
]);

it('hides active products belonging to a non-visible company', function () {
    $company = Company::factory()->create(); // draft company
    $product = Product::factory()->active()->for($company)->create(['name' => 'Shadow Supplier Board']);

    $this->get(route('marketplace'))->assertOk()->assertDontSee('Shadow Supplier Board');
    $this->get(route('products.show', $product->slug))->assertNotFound();
});

it('filters the marketplace by species', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $wanted = Species::factory()->create(['common_name' => 'Wantedwood']);
    $other = Species::factory()->create(['common_name' => 'Otherwood']);

    Product::factory()->active()->for($company)->for($wanted)->create(['name' => 'Wanted Species Board']);
    Product::factory()->active()->for($company)->for($other)->create(['name' => 'Other Species Board']);

    $this->get(route('marketplace', ['species' => $wanted->slug]))
        ->assertOk()
        ->assertSee('Wanted Species Board')
        ->assertDontSee('Other Species Board');
});

it('filters the marketplace by product type', function () {
    $company = Company::factory()->publiclyVisible()->create();

    Product::factory()->active()->for($company)->create([
        'name' => 'Type Match Logs', 'product_type' => ProductType::Logs,
    ]);
    Product::factory()->active()->for($company)->create([
        'name' => 'Type Mismatch Veneer', 'product_type' => ProductType::Veneer,
    ]);

    $this->get(route('marketplace', ['type' => ProductType::Logs->value]))
        ->assertOk()
        ->assertSee('Type Match Logs')
        ->assertDontSee('Type Mismatch Veneer');
});

it('returns matching products, companies and species from the search page', function () {
    $company = Company::factory()->publiclyVisible()->create(['legal_name' => 'Zingiber Timber Sarl']);
    $species = Species::factory()->create(['common_name' => 'Zingiber']);
    Product::factory()->active()->for($company)->for($species)->create(['name' => 'Zingiber Sawn Timber']);

    $this->get(route('search', ['q' => 'Zingiber']))
        ->assertOk()
        ->assertSee('Zingiber Sawn Timber')
        ->assertSee('Zingiber Timber Sarl')
        ->assertSee('Zingiber');
});

it('gates the Filament product resource on the products.manage permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get('/admin/products')->assertOk();

    auth()->logout();

    $officer = User::factory()->create();
    $officer->assignRole('verification_officer');
    $this->actingAs($officer)->get('/admin/products')->assertForbidden();
});
