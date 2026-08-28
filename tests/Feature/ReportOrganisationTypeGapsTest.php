<?php

use App\Enums\SupplierType;
use App\Models\Company;

it('lists companies whose supplier_type has no OrganisationType mapping', function () {
    $exporter = Company::factory()->create(['legal_name' => 'Exporter Co', 'supplier_type' => SupplierType::Exporter->value, 'type' => null]);
    $trader = Company::factory()->create(['legal_name' => 'Trader Co', 'supplier_type' => SupplierType::Trader->value, 'type' => null]);
    Company::factory()->create(['legal_name' => 'Manufacturer Co', 'supplier_type' => SupplierType::Manufacturer->value, 'type' => 'manufacturer']);

    $this->artisan('companies:organisation-type-gaps')
        ->assertSuccessful()
        ->expectsOutputToContain('Exporter Co')
        ->expectsOutputToContain('Trader Co')
        ->doesntExpectOutputToContain('Manufacturer Co');
});

it('reports zero gaps cleanly when everything mappable is backfilled', function () {
    Company::factory()->create(['supplier_type' => SupplierType::Manufacturer->value, 'type' => 'manufacturer']);

    $this->artisan('companies:organisation-type-gaps')
        ->assertSuccessful()
        ->expectsOutputToContain('0 companies');
});
