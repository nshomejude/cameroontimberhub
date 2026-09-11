<?php

use App\Enums\SupplierType;
use App\Models\Company;

test('Processor case exists with non-empty labels', function () {
    expect(SupplierType::Processor->value)->toBe('processor')
        ->and(SupplierType::Processor->label())->not->toBeEmpty()
        ->and(SupplierType::Processor->pluralLabel())->not->toBeEmpty();
});

test('a company can be saved with supplier_type = processor', function () {
    $company = Company::factory()->create(['supplier_type' => SupplierType::Processor]);

    expect($company->refresh()->supplier_type)->toBe(SupplierType::Processor);
});

test('GET /api/v1/suppliers?types[]=processor returns an empty data array when none are seeded', function () {
    Company::factory()->publiclyVisible()->create(['supplier_type' => SupplierType::Manufacturer]);

    $this->getJson('/api/v1/suppliers?types[]=processor')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('GET /api/v1/suppliers?types[]=manufacturer still works unaffected', function () {
    Company::factory()->publiclyVisible()->create(['supplier_type' => SupplierType::Manufacturer]);

    $this->getJson('/api/v1/suppliers?types[]=manufacturer')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
