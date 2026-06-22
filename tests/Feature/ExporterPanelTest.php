<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function exporterFor(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('lets a company member reach the dashboard and edit their own company', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs(exporterFor($company));

    $this->get('/dashboard')->assertOk();
    $this->get('/dashboard/companies')->assertOk();
    $this->get('/dashboard/companies/'.$company->slug.'/edit')->assertOk();
});

it('stops a company member from editing another company', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();

    $this->actingAs(exporterFor($own));

    $this->get('/dashboard/companies/'.$other->slug.'/edit')->assertNotFound();
});

it('blocks the dashboard for a user with no company', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertForbidden();
});
