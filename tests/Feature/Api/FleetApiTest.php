<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function fleetApiUser(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['type' => OrganisationType::Logistics]);
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    return [$user, $company];
}

/* --------------------------------------------------------------- vehicles */

it('lists only the callers own company vehicles', function () {
    [$user, $company] = fleetApiUser();
    $other = Company::factory()->create(['type' => OrganisationType::Logistics]);

    $mine = Vehicle::factory()->create(['company_id' => $company->id]);
    Vehicle::factory()->create(['company_id' => $other->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/vehicles')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($mine->id)->and($ids)->toHaveCount(1);
});

it('creates a vehicle for the callers company', function () {
    [$user] = fleetApiUser();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/fleet/vehicles', [
            'registration_number' => 'CM-1234-AB',
            'type' => 'truck',
            'capacity_tonnes' => 12.5,
        ])
        ->assertCreated();

    $response->assertJsonPath('data.registration_number', 'CM-1234-AB')
        ->assertJsonPath('data.type', 'truck')
        ->assertJsonPath('data.is_active', true);
});

it('validates vehicle creation with the standard error envelope', function () {
    [$user] = fleetApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/fleet/vehicles', ['type' => 'not-a-real-type'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['registration_number', 'type'], 'error.details');
});

it('shows and updates one of the callers own vehicles, scoped by company', function () {
    [$user, $company] = fleetApiUser();
    $vehicle = Vehicle::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/vehicles/'.$vehicle->id)
        ->assertOk()
        ->assertJsonPath('data.id', $vehicle->id);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/supplier/fleet/vehicles/'.$vehicle->id, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('404s another companys vehicle on show and update', function () {
    [$user] = fleetApiUser();
    $other = Company::factory()->create(['type' => OrganisationType::Logistics]);
    $theirs = Vehicle::factory()->create(['company_id' => $other->id]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/vehicles/'.$theirs->id)
        ->assertNotFound();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/supplier/fleet/vehicles/'.$theirs->id, ['is_active' => false])
        ->assertNotFound();
});

/* ---------------------------------------------------------------- drivers */

it('lists only the callers own company drivers', function () {
    [$user, $company] = fleetApiUser();
    $other = Company::factory()->create(['type' => OrganisationType::Logistics]);

    $mine = Driver::factory()->create(['company_id' => $company->id]);
    Driver::factory()->create(['company_id' => $other->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/drivers')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($mine->id)->and($ids)->toHaveCount(1);
});

it('creates a driver for the callers company', function () {
    [$user] = fleetApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/fleet/drivers', [
            'name' => 'Jean Mbarga',
            'license_number' => 'DL-99999XY',
            'phone' => '+237600000000',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Jean Mbarga')
        ->assertJsonPath('data.is_active', true);
});

it('validates driver creation with the standard error envelope', function () {
    [$user] = fleetApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/fleet/drivers', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'license_number'], 'error.details');
});

it('shows and updates one of the callers own drivers, scoped by company', function () {
    [$user, $company] = fleetApiUser();
    $driver = Driver::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/drivers/'.$driver->id)
        ->assertOk()
        ->assertJsonPath('data.id', $driver->id);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/supplier/fleet/drivers/'.$driver->id, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('404s another companys driver on show and update', function () {
    [$user] = fleetApiUser();
    $other = Company::factory()->create(['type' => OrganisationType::Logistics]);
    $theirs = Driver::factory()->create(['company_id' => $other->id]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/drivers/'.$theirs->id)
        ->assertNotFound();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/supplier/fleet/drivers/'.$theirs->id, ['is_active' => false])
        ->assertNotFound();
});

/* ---------------------------------------------------------------- gating */

it('401s a guest on fleet endpoints', function () {
    $this->getJson('/api/v1/supplier/fleet/vehicles')->assertUnauthorized();
    $this->getJson('/api/v1/supplier/fleet/drivers')->assertUnauthorized();
});

it('403s a non-company buyer on fleet endpoints (api.supplier gate, regression)', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/vehicles')
        ->assertForbidden();
});

it('403s a company member of a non-logistics company (fleet eligibility gate)', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['type' => OrganisationType::Manufacturer]);
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/vehicles')
        ->assertForbidden();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/drivers')
        ->assertForbidden();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/fleet/vehicles', ['registration_number' => 'X', 'type' => 'truck'])
        ->assertForbidden();
});

it('allows a logistics_partner member of a non-logistics company through the fleet eligibility gate', function () {
    $user = User::factory()->create();
    $user->assignRole('logistics_partner');
    $company = Company::factory()->create(['type' => OrganisationType::Manufacturer]);
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/fleet/vehicles')
        ->assertOk();
});
