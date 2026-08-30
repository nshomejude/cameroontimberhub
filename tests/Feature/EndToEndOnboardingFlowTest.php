<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Exercises the FULL chain for each of the 5 company-forming account-
 * capability roles, through the real HTTP /register endpoint (not
 * RegisterAccount called directly), following the redirect chain to a
 * final 200 and then loading that company's exporter dashboard -- the
 * whole thing other agents only unit/feature-tested piece by piece.
 */
dataset('company_forming_account_types', [
    'supplier' => ['supplier', OrganisationType::Supplier],
    'processor' => ['processor', OrganisationType::Processor],
    'artisan' => ['artisan', OrganisationType::Artisan],
    'logistics_partner' => ['logistics_partner', OrganisationType::Logistics],
    'carbon_developer' => ['carbon_developer', OrganisationType::CarbonDeveloper],
]);

it('registers, logs in, creates the right company, and reaches a working dashboard end to end', function (string $accountType, OrganisationType $expectedType) {
    $email = $accountType.'-'.uniqid().'@example.test';

    $response = $this->post(route('register.store'), [
        'account_type' => $accountType,
        'name' => 'E2E Test User',
        'email' => $email,
        'company_name' => 'E2E '.ucfirst($accountType).' Co',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ]);

    // (a) the user is logged in.
    $user = User::where('email', $email)->firstOrFail();
    $this->assertAuthenticatedAs($user);

    // (b) a Company was created with the correct OrganisationType.
    $company = Company::where('created_by', $user->id)->firstOrFail();
    expect($company->type)->toBe($expectedType);
    expect($user->hasRole($accountType))->toBeTrue();

    // (c) the user lands somewhere sensible and gets a 200, following the
    // full redirect chain (registration -> possibly onboarding checklist).
    $landing = $this->followRedirects($response);
    $landing->assertOk();

    // (d) that company's exporter dashboard loads without error for this
    // user (may itself redirect once to the onboarding checklist, which is
    // expected/working-as-intended for a freshly registered company).
    $dashboard = $this->followRedirects($this->get('/dashboard'));
    $dashboard->assertOk();
})->with('company_forming_account_types');

it('registers a pure-demand buyer role account end to end with no company and a working buyer landing', function () {
    $email = 'e2e-buyer-'.uniqid().'@example.test';

    $response = $this->post(route('register.store'), [
        'account_type' => 'buyer',
        'name' => 'E2E Buyer',
        'email' => $email,
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ]);

    $user = User::where('email', $email)->firstOrFail();
    $this->assertAuthenticatedAs($user);

    expect($user->companies()->exists())->toBeFalse();
    expect($user->hasRole('buyer'))->toBeTrue();

    $landing = $this->followRedirects($response);
    $landing->assertOk();
});
