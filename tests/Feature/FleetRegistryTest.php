<?php

use App\Filament\Exporter\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Exporter\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Exporter\Resources\Vehicles\Pages\EditVehicle;
use App\Models\Company;
use App\Models\Document;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Fleet & driver registry (gap-plan item 1.5.12) — the exporter-panel
 * Vehicles / Drivers resources. Company-scoped, full CRUD, compliance-doc
 * status derived from the shared Document store. Mirrors
 * ExporterCapacityResourceTest.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

function fleetMember(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('lists only the acting company vehicles and drivers', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();

    Vehicle::factory()->for($own)->create(['registration_number' => 'CM-1234-AB']);
    Vehicle::factory()->for($other)->create(['registration_number' => 'CM-9999-ZZ']);
    Driver::factory()->for($own)->create(['name' => 'Jean Mbarga']);
    Driver::factory()->for($other)->create(['name' => 'Other Person']);

    $user = fleetMember($own);

    $this->actingAs($user)->get('/dashboard/vehicles')
        ->assertOk()->assertSee('CM-1234-AB')->assertDontSee('CM-9999-ZZ');

    $this->actingAs($user)->get('/dashboard/drivers')
        ->assertOk()->assertSee('Jean Mbarga')->assertDontSee('Other Person');
});

it('creates a vehicle scoped to the acting company', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs(fleetMember($company));

    Livewire::test(CreateVehicle::class)
        ->fillForm([
            'registration_number' => 'CM-7777-XY',
            'type' => 'truck',
            'capacity_tonnes' => 12.5,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Vehicle::where('registration_number', 'CM-7777-XY')->first())
        ->not->toBeNull()
        ->company_id->toBe($company->getKey());
});

it('creates a driver scoped to the acting company', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs(fleetMember($company));

    Livewire::test(CreateDriver::class)
        ->fillForm([
            'name' => 'Aicha Nkeng',
            'license_number' => 'DL-55501AB',
            'phone' => '+237655000111',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Driver::where('license_number', 'DL-55501AB')->first())
        ->not->toBeNull()
        ->company_id->toBe($company->getKey());
});

it('cannot be spoofed to another company on vehicle create', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();

    $this->actingAs(fleetMember($company));

    Livewire::test(CreateVehicle::class)
        ->fillForm(['registration_number' => 'CM-0001-AA', 'type' => 'van', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Vehicle::first()->company_id)->toBe($company->getKey())
        ->and(Vehicle::first()->company_id)->not->toBe($other->getKey());
});

it('edits and deletes a vehicle', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $vehicle = Vehicle::factory()->for($company)->create(['type' => 'van']);

    $this->actingAs(fleetMember($company));

    Livewire::test(EditVehicle::class, ['record' => $vehicle->getKey()])
        ->fillForm(['type' => 'flatbed'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('delete');

    expect($vehicle->fresh())->toBeNull();
});

it('cannot reach another company vehicle edit page by direct id', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();
    $theirs = Vehicle::factory()->for($other)->create();

    $this->actingAs(fleetMember($own))
        ->get('/dashboard/vehicles/'.$theirs->getKey().'/edit')
        ->assertNotFound();
});

it('shows the derived compliance-document state on the vehicle table', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $vehicle = Vehicle::factory()->for($company)->create();
    Document::factory()->for($vehicle, 'owner')->create(['expires_at' => now()->addDays(10)]);

    $this->actingAs(fleetMember($company))
        ->get('/dashboard/vehicles')
        ->assertOk()
        ->assertSee('Expiring soon');
});

it('hides the fleet resources from a user with no company', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard/vehicles')->assertForbidden();
    $this->get('/dashboard/drivers')->assertForbidden();
});
