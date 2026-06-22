<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function staff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('renders the species resource pages for a content manager', function () {
    $this->actingAs(staff('content_manager'));

    $this->get('/admin/species')->assertOk();
    $this->get('/admin/species/create')->assertOk();
});

it('renders the company resource list and edit for an admin', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs(staff('admin'));

    $this->get('/admin/companies')->assertOk();
    // Company's route key is its slug (set for public URLs), so Filament binds by slug.
    $this->get('/admin/companies/'.$company->slug.'/edit')->assertOk();
});

it('hides species management from a verification officer', function () {
    $this->actingAs(staff('verification_officer'));

    $this->get('/admin/species')->assertForbidden();
});

it('blocks the admin panel for a user with no platform role', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertForbidden();
});
