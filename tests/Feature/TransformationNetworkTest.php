<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\Species;

it('lists only verified processor and manufacturer companies', function () {
    $processor = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $manufacturer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'verified_at' => now()]);
    $supplier = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Supplier, 'verified_at' => now()]);
    $unverifiedProcessor = Company::factory()->create(['status' => CompanyStatus::Draft, 'type' => OrganisationType::Processor]);

    $response = $this->get('/transformation-network');

    $response->assertOk();
    $response->assertSee($processor->name);
    $response->assertSee($manufacturer->name);
    $response->assertDontSee($supplier->name);
    $response->assertDontSee($unverifiedProcessor->name);
});

it('filters the directory by region', function () {
    $centre = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'region' => 'Centre', 'verified_at' => now()]);
    $littoral = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'region' => 'Littoral', 'verified_at' => now()]);

    $response = $this->get('/transformation-network?region=Centre');

    $response->assertSee($centre->name);
    $response->assertDontSee($littoral->name);
});

it('filters the directory by capability', function () {
    $kilnDryer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $kilnDryer->capacities()->create(['capability' => 'Kiln drying', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $sawyer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $sawyer->capacities()->create(['capability' => 'Sawing', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $response = $this->get('/transformation-network?capability=Kiln drying');

    $response->assertSee($kilnDryer->name);
    $response->assertDontSee($sawyer->name);
});

it('filters the directory by processor/manufacturer type', function () {
    $processor = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $manufacturer = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'verified_at' => now()]);

    $response = $this->get('/transformation-network?type=processor');

    $response->assertSee($processor->name);
    $response->assertDontSee($manufacturer->name);
});

it('matches a processor with enough capacity and the right species', function () {
    $ayous = Species::factory()->create(['common_name' => 'Ayous']);

    $matchingProcessor = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $matchingProcessor->species()->attach($ayous);
    $matchingProcessor->capacities()->create(['capability' => 'Sawing', 'quantity' => 200, 'unit' => 'm3', 'period' => 'month']);

    $tooSmall = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Manufacturer, 'verified_at' => now()]);
    $tooSmall->species()->attach($ayous);
    $tooSmall->capacities()->create(['capability' => 'Sawing', 'quantity' => 50, 'unit' => 'm3', 'period' => 'month']);

    $wrongSpecies = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Processor, 'verified_at' => now()]);
    $wrongSpecies->capacities()->create(['capability' => 'Sawing', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $supplierWithCapacity = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Supplier, 'verified_at' => now()]);
    $supplierWithCapacity->species()->attach($ayous);
    $supplierWithCapacity->capacities()->create(['capability' => 'Sawing', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month']);

    $response = $this->get("/transformation-network/match?species={$ayous->slug}&quantity=100&period=month");

    $response->assertOk();
    $response->assertSee($matchingProcessor->name);
    $response->assertDontSee($tooSmall->name);
    $response->assertDontSee($wrongSpecies->name);
    $response->assertDontSee($supplierWithCapacity->name);
});

it('shows the match form with no results when nothing has been searched yet', function () {
    $response = $this->get('/transformation-network/match');

    $response->assertOk();
    $response->assertSee('Find a Transformer');
});

it('links to the Transformation Network from the homepage', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(route('transformation-network', ['type' => 'processor']), false);
    $response->assertSee(route('transformation-network', ['type' => 'manufacturer']), false);
});
