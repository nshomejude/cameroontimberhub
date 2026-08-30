<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Models\Capacity;
use App\Models\Company;

it('still renders 200', function () {
    $response = $this->get('/logistics-directory');

    $response->assertOk();
});

it('shows a capacity chip when the company has one', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Verified,
        'type' => OrganisationType::Logistics,
    ]);

    $capacity = Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Refrigerated Trucking',
        'quantity' => 500,
        'unit' => 'm3',
        'period' => 'month',
    ]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSee($capacity->capability);
});
