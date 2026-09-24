<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\LotTransformation;
use App\Models\Species;
use App\Models\User;

it('lists only verified processor/manufacturer companies as providers', function () {
    $processor = Company::factory()->create([
        'type' => OrganisationType::Processor,
        'status' => CompanyStatus::Verified,
    ]);

    Company::factory()->create([
        'type' => OrganisationType::Processor,
        'status' => CompanyStatus::Pending,
    ]);

    Company::factory()->create([
        'type' => OrganisationType::Supplier,
        'status' => CompanyStatus::Verified,
    ]);

    $response = $this->getJson('/api/v1/transformation/providers')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toEqual(collect([$processor->getKey()]));
});

it('404s a provider profile for an unverified company', function () {
    $company = Company::factory()->create([
        'type' => OrganisationType::Manufacturer,
        'status' => CompanyStatus::Pending,
    ]);

    $this->getJson("/api/v1/transformation/providers/{$company->slug}")->assertNotFound();
});

it('404s a provider profile for a nonexistent company', function () {
    $this->getJson('/api/v1/transformation/providers/does-not-exist')->assertNotFound();
});

it('shows a verified provider profile with its capacities', function () {
    $company = Company::factory()->create([
        'type' => OrganisationType::Processor,
        'status' => CompanyStatus::Verified,
    ]);

    Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $company->getKey(),
        'capability' => 'Kiln drying',
        'quantity' => 500,
        'period' => 'month',
    ]);

    $this->getJson("/api/v1/transformation/providers/{$company->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $company->slug)
        ->assertJsonCount(1, 'capacities');
});

it('requires species and quantity to return match results', function () {
    $species = Species::factory()->create(['slug' => 'ayous']);

    $company = Company::factory()->create([
        'type' => OrganisationType::Processor,
        'status' => CompanyStatus::Verified,
    ]);
    $company->species()->attach($species->getKey());

    Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $company->getKey(),
        'capability' => 'Sawing',
        'quantity' => 200,
        'period' => 'month',
    ]);

    // Missing quantity -> empty, even with a matching species.
    $this->getJson('/api/v1/transformation/match?species=ayous')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Missing species -> empty, even with a matching quantity.
    $this->getJson('/api/v1/transformation/match?quantity=100')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Both present -> real match.
    $this->getJson('/api/v1/transformation/match?species=ayous&quantity=100&period=month')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $company->getKey());
});

it('includes real capacities/transformations counts in a processor company dashboard type_stats', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['type' => OrganisationType::Processor]);
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $company->getKey(),
    ]);

    LotTransformation::create([
        'processor_company_id' => $company->getKey(),
        'transformation_type' => 'sawing',
        'input_volume_m3' => 100,
        'output_volume_m3' => 72,
        'loss_volume_m3' => 28,
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk();

    $typeStats = collect($response->json('data.type_stats'))->keyBy('key');

    expect($typeStats->get('capacities')['value'])->toBe(1)
        ->and($typeStats->get('transformations')['value'])->toBe(1)
        ->and((float) $typeStats->get('output_volume_m3')['value'])->toBe(72.0);
});
