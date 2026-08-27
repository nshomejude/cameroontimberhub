<?php

use App\Enums\OrganisationType;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Support\SupplierTypeMigrationMap;

it('maps only the two unambiguous SupplierType values', function () {
    expect(SupplierTypeMigrationMap::MAP)->toBe([
        'manufacturer' => 'manufacturer',
        'logistics_provider' => 'logistics',
    ]);
});

it('leaves exporter, trader and service_provider unmapped', function () {
    foreach (['exporter', 'trader', 'service_provider'] as $blocked) {
        expect(SupplierTypeMigrationMap::MAP)->not->toHaveKey($blocked);
    }
});

it('backfills companies with a mappable supplier_type and leaves type null for the rest', function () {
    $manufacturer = Company::factory()->create(['supplier_type' => SupplierType::Manufacturer->value, 'type' => null]);
    $logistics = Company::factory()->create(['supplier_type' => SupplierType::LogisticsProvider->value, 'type' => null]);
    $exporter = Company::factory()->create(['supplier_type' => SupplierType::Exporter->value, 'type' => null]);
    $trader = Company::factory()->create(['supplier_type' => SupplierType::Trader->value, 'type' => null]);
    $untyped = Company::factory()->create(['supplier_type' => null, 'type' => null]);

    $this->artisan('companies:backfill-organisation-type')
        ->assertSuccessful();

    expect($manufacturer->fresh()->type)->toBe(OrganisationType::Manufacturer)
        ->and($logistics->fresh()->type)->toBe(OrganisationType::Logistics)
        ->and($exporter->fresh()->type)->toBeNull()
        ->and($trader->fresh()->type)->toBeNull()
        ->and($untyped->fresh()->type)->toBeNull();
});

it('is idempotent: running it twice does not change already-backfilled rows or error', function () {
    $company = Company::factory()->create(['supplier_type' => SupplierType::Manufacturer->value, 'type' => null]);

    $this->artisan('companies:backfill-organisation-type')->assertSuccessful();
    $firstRunType = $company->fresh()->type;

    $this->artisan('companies:backfill-organisation-type')->assertSuccessful();

    expect($company->fresh()->type)->toBe($firstRunType);
});

it('never overwrites a type that was already set manually', function () {
    $company = Company::factory()->create([
        'supplier_type' => SupplierType::Manufacturer->value,
        'type' => OrganisationType::Supplier->value,
    ]);

    $this->artisan('companies:backfill-organisation-type')->assertSuccessful();

    expect($company->fresh()->type)->toBe(OrganisationType::Supplier);
});
