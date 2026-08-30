<?php

use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Models\Capacity;
use App\Models\CarbonProject;
use App\Models\Company;
use App\Models\Product;

it('shows the capacity tab for a company with capacity rows', function () {
    $company = Company::factory()->publiclyVisible()->create();

    Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Trucking',
        'quantity' => 500,
        'unit' => 'm3',
        'period' => 'month',
    ]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertSee('Capacity');
    $response->assertSee('Trucking');
});

it('does not show the capacity tab for a company with no capacity rows', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertDontSee('id="capacity"', false);
});

it('shows the carbon projects tab for a CarbonDeveloper company with active projects', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::CarbonDeveloper,
    ]);

    CarbonProject::factory()->create([
        'company_id' => $company->id,
        'name' => 'Mbalmayo Reforestation Project',
        'status' => ProductStatus::Active,
    ]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertSee('Carbon Projects');
    $response->assertSee('Mbalmayo Reforestation Project');
});

it('does not show the carbon projects tab for a non-CarbonDeveloper company', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::Supplier,
    ]);

    CarbonProject::factory()->create([
        'company_id' => $company->id,
        'status' => ProductStatus::Active,
    ]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertDontSee('Carbon projects from');
});

it('does not show the carbon projects tab for a CarbonDeveloper company with zero projects', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::CarbonDeveloper,
    ]);

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertDontSee('Carbon projects from');
});

it('still shows the products tab as before', function () {
    $company = Company::factory()->publiclyVisible()->create();

    Product::factory()->for($company)->active()->create();

    $response = $this->get(route('companies.show', $company->slug));

    $response->assertOk();
    $response->assertSee('Products');
});
