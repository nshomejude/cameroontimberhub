<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;

it('redirects a guest to login', function () {
    $this->get('/fleet')->assertRedirect('/login');
});

it('404s for a signed-in user with no company', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/fleet')->assertNotFound();
});

it('shows only the signed-in user own company fleet and drivers', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);

    $vehicle = Vehicle::factory()->for($company)->create(['registration_number' => 'CM-1234-AB']);
    $driver = Driver::factory()->for($company)->create(['name' => 'Jean Mbarga']);

    $otherCompany = Company::factory()->create();
    $otherVehicle = Vehicle::factory()->for($otherCompany)->create(['registration_number' => 'CM-9999-ZZ']);

    $response = $this->actingAs($user)->get('/fleet');

    $response->assertOk();
    $response->assertSee('CM-1234-AB');
    $response->assertSee('Jean Mbarga');
    $response->assertDontSee('CM-9999-ZZ');
});

it('flags a vehicle document nearing expiry', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);

    $vehicle = Vehicle::factory()->for($company)->create();
    Document::factory()->for($vehicle, 'owner')->create([
        'type' => 'insurance_certificate',
        'expires_at' => now()->addDays(10),
    ]);

    $response = $this->actingAs($user)->get('/fleet');

    $response->assertOk();
    $response->assertSee('Expiring soon');
});
