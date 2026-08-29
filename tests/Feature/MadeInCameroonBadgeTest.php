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

test('the Made in Cameroon landing page lists only qualifying products', function () {
    $qualifying = Product::factory()->for(Company::factory()->create([
        'country_code' => 'CM',
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Manufacturer,
        'legal_name' => 'Douala Furnitures SARL',
    ]))->create(['status' => ProductStatus::Active, 'name' => 'Qualifying Iroko Table']);

    $notQualifying = Product::factory()->for(Company::factory()->create([
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
