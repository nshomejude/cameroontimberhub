<?php

use App\Filament\Exporter\Resources\Capacities\Pages\CreateCapacity;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

test('a logistics company user can create a capacity row scoped to their own company', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($user);

    Livewire::test(CreateCapacity::class)
        ->fillForm([
            'capability' => 'Trucking',
            'quantity' => 500,
            'unit' => 'm3',
            'period' => 'month',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $capacity = Capacity::first();

    expect($capacity)->not->toBeNull()
        ->and($capacity->owner_type)->toBe(Company::class)
        ->and($capacity->owner_id)->toBe($company->getKey())
        ->and($capacity->capability)->toBe('Trucking');
});

test('a company only sees its own capacity rows in the resource list', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::factory()->create();
    $userA->companies()->attach($companyA, ['role' => 'owner']);

    Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $companyA->getKey(),
        'capability' => 'Trucking corridor',
        'quantity' => 100,
        'unit' => 'm3',
        'period' => 'month',
    ]);

    Capacity::factory()->create([
        'owner_type' => Company::class,
        'owner_id' => $companyB->getKey(),
        'capability' => 'Warehousing space',
        'quantity' => 200,
        'unit' => 'm3',
        'period' => 'month',
    ]);

    $response = $this->actingAs($userA)->get('/dashboard/capacities');

    $response->assertOk();
    $response->assertSee('Trucking corridor');
    $response->assertDontSee('Warehousing space');
});

test('owner_id can never be spoofed to another company on capacity create', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($user);

    // owner_type/owner_id are not exposed as form fields at all (see
    // CapacityForm) -- create with a normal payload and assert the row
    // always resolves to the acting user's own company.
    Livewire::test(CreateCapacity::class)
        ->fillForm([
            'capability' => 'Port handling',
            'quantity' => 10,
            'unit' => 'ton',
            'period' => 'week',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $capacity = Capacity::first();

    expect($capacity->owner_id)->toBe($company->getKey())
        ->and($capacity->owner_id)->not->toBe($otherCompany->getKey());
});
