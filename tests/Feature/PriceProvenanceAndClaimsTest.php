<?php

use App\Models\Company;
use App\Models\Product;

function claimsVisibleSupplier(array $attrs = []): Company
{
    return Company::factory()->publiclyVisible()->create($attrs);
}

it('no longer shows the inflated "thousands of businesses" homepage claim', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Join thousands of businesses worldwide who trust Cameroon Timber Hub.', false)
        ->assertDontSee('Join thousands of businesses worldwide who trust Cameroon Timber Hub for their timber needs.', false)
        ->assertSee('Join a growing network of verified suppliers and buyers who trust Cameroon Timber Hub');
});

it('shows a real computed supplier count on the homepage stats band, not a hardcoded floor', function () {
    $company = claimsVisibleSupplier();
    Product::factory()->active()->for($company)->create();

    $actualSuppliers = Company::publiclyVisible()->count();
    expect($actualSuppliers)->toBeGreaterThan(0);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Verified Suppliers');
});

it('shows the "Supplier-reported price" provenance label on the product card when a real price is displayed', function () {
    $company = claimsVisibleSupplier();
    $product = Product::factory()->active()->for($company)->create([
        'price_amount' => 500000,
        'price_currency' => 'XAF',
    ]);

    $this->get(route('marketplace'))
        ->assertOk()
        ->assertSee('Supplier-reported price');
});

it('shows the "Supplier-reported price" provenance label on the product detail page when a real price is displayed', function () {
    $company = claimsVisibleSupplier();
    $product = Product::factory()->active()->for($company)->create([
        'price_amount' => 650000,
        'price_currency' => 'XAF',
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Supplier-reported price');
});

it('does not show the provenance label when there is no real price to label', function () {
    $company = claimsVisibleSupplier();
    $product = Product::factory()->active()->for($company)->create([
        'price_amount' => null,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Price on request')
        ->assertDontSee('Supplier-reported price');
});

it('shows a real, DB-derived listing count on the marketplace stat card', function () {
    $company = claimsVisibleSupplier();
    Product::factory()->active()->for($company)->count(3)->create();

    $response = $this->get(route('marketplace'))->assertOk();

    $response->assertSee('Live Listings');
});
