<?php

use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;

it('only returns active products from publicly visible companies', function () {
    $visibleCompany = Company::factory()->publiclyVisible()->create();
    $hiddenCompany = Company::factory()->create();

    $activeVisible = Product::factory()->create([
        'company_id' => $visibleCompany->getKey(),
        'status' => ProductStatus::Active,
    ]);

    Product::factory()->create([
        'company_id' => $visibleCompany->getKey(),
        'status' => ProductStatus::Draft,
    ]);

    Product::factory()->create([
        'company_id' => $hiddenCompany->getKey(),
        'status' => ProductStatus::Active,
    ]);

    $response = $this->getJson('/api/v1/local/listings')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($activeVisible->getKey())
        ->and($ids)->toHaveCount(1);
});

it('filters local listings by region', function () {
    $company = Company::factory()->publiclyVisible()->create(['region' => 'Centre']);
    $other = Company::factory()->publiclyVisible()->create(['region' => 'Littoral']);

    $match = Product::factory()->create(['company_id' => $company->getKey(), 'status' => ProductStatus::Active]);
    Product::factory()->create(['company_id' => $other->getKey(), 'status' => ProductStatus::Active]);

    $response = $this->getJson('/api/v1/local/listings?region=Centre')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toEqual(collect([$match->getKey()]));
});

it('404s a local listing that is not active or not publicly visible', function () {
    $hiddenCompany = Company::factory()->create();
    $product = Product::factory()->create([
        'company_id' => $hiddenCompany->getKey(),
        'status' => ProductStatus::Active,
    ]);

    $this->getJson("/api/v1/local/listings/{$product->getKey()}")->assertNotFound();
});

it('shows a single active local listing from a publicly visible company', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->getKey(),
        'status' => ProductStatus::Active,
    ]);

    $this->getJson("/api/v1/local/listings/{$product->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->getKey());
});

it('404s a yard profile for a non-public company', function () {
    $company = Company::factory()->create();

    $this->getJson("/api/v1/local/yards/{$company->slug}")->assertNotFound();
});

it('shows a public yard profile for a publicly visible company', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->getJson("/api/v1/local/yards/{$company->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $company->slug);
});

it('keeps type_stats empty for a plain export supplier dashboard', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.type_stats', []);
});
