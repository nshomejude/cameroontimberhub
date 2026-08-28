<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets a company-owning user with the buyer role also reach buyer-only routes, instead of always redirecting to /dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('supplier', 'buyer');
    $company = Company::factory()->create();
    $company->users()->attach($user->id, ['role' => 'owner', 'is_primary' => true]);

    $response = $this->actingAs($user)->get(route('account.index'));

    $response->assertOk(); // was previously a redirect to /dashboard purely because the user owns a company
});

it('still redirects a company-owning user with no buyer role to /dashboard, unchanged behaviour', function () {
    $user = User::factory()->create();
    $user->assignRole('supplier');
    $company = Company::factory()->create();
    $company->users()->attach($user->id, ['role' => 'owner', 'is_primary' => true]);

    $response = $this->actingAs($user)->get(route('account.index'));

    $response->assertRedirect('/dashboard');
});
