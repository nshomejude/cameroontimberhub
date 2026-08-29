<?php

use App\Models\Capacity;
use App\Models\Company;

it('gives Company a capacities relation via HasCapacities', function () {
    $company = Company::factory()->create();
    Capacity::factory()->for($company, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 100, 'period' => 'month']);

    expect($company->capacities()->count())->toBe(1)
        ->and($company->capacities()->first()->capability)->toBe('Kiln drying');
});
