<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Covers RegisterAccount's support for all 7 seeded account-capability
 * roles (RolesAndPermissionsSeeder::ACCOUNT_ROLES): 5 company-forming roles
 * (supplier, processor, artisan, logistics_partner, carbon_developer) and
 * 2 pure-demand roles with no company (buyer, carbon_buyer).
 */
function validRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'account_type' => 'buyer',
        'name' => 'Test User',
        'email' => 'user'.uniqid().'@example.test',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ], $overrides);
}

it('registers a buyer with no company', function () {
    $email = 'buyer'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'buyer',
        'email' => $email,
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();

    expect($user->companies()->exists())->toBeFalse();
    expect($user->hasRole('buyer'))->toBeTrue();
});

it('registers a carbon_buyer with no company', function () {
    config(['timber.signup.carbon_enabled' => true]);
    $email = 'carbonbuyer'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'carbon_buyer',
        'email' => $email,
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();

    expect($user->companies()->exists())->toBeFalse();
    expect($user->hasRole('carbon_buyer'))->toBeTrue();
});

it('registers a supplier with a company of the correct type', function () {
    $email = 'supplier'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'supplier',
        'email' => $email,
        'company_name' => 'Acme Supplier Co',
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();
    $company = Company::where('created_by', $user->id)->firstOrFail();

    expect($company->type)->toBe(OrganisationType::Supplier);
    expect($user->hasRole('supplier'))->toBeTrue();
});

it('registers a processor with a company of the correct type', function () {
    $email = 'processor'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'processor',
        'email' => $email,
        'company_name' => 'Acme Processor Co',
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();
    $company = Company::where('created_by', $user->id)->firstOrFail();

    expect($company->type)->toBe(OrganisationType::Processor);
    expect($user->hasRole('processor'))->toBeTrue();
});

it('registers an artisan with a company of the correct type', function () {
    $email = 'artisan'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'artisan',
        'email' => $email,
        'company_name' => 'Acme Artisan Co',
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();
    $company = Company::where('created_by', $user->id)->firstOrFail();

    expect($company->type)->toBe(OrganisationType::Artisan);
    expect($user->hasRole('artisan'))->toBeTrue();
});

it('registers a logistics_partner with a company of the correct type', function () {
    $email = 'logistics'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'logistics_partner',
        'email' => $email,
        'company_name' => 'Acme Logistics Co',
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();
    $company = Company::where('created_by', $user->id)->firstOrFail();

    expect($company->type)->toBe(OrganisationType::Logistics);
    expect($user->hasRole('logistics_partner'))->toBeTrue();
});

it('registers a carbon_developer with a company of the correct type', function () {
    config(['timber.signup.carbon_enabled' => true]);
    $email = 'carbondev'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => 'carbon_developer',
        'email' => $email,
        'company_name' => 'Acme Carbon Co',
    ]))->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();
    $company = Company::where('created_by', $user->id)->firstOrFail();

    expect($company->type)->toBe(OrganisationType::CarbonDeveloper);
    expect($user->hasRole('carbon_developer'))->toBeTrue();
});

it('renders all 7 account type options on the registration form when carbon signup is enabled', function () {
    config(['timber.signup.carbon_enabled' => true]);
    $response = $this->get(route('register'));

    $response->assertOk();

    foreach (['buyer', 'supplier', 'processor', 'artisan', 'logistics_partner', 'carbon_developer', 'carbon_buyer'] as $type) {
        $response->assertSee('value="'.$type.'"', false);
    }
});

it('rejects carbon account types on web registration while carbon signup is disabled', function (string $type) {
    config(['timber.signup.carbon_enabled' => false]);
    $email = 'dormant'.uniqid().'@example.test';

    $this->post(route('register.store'), validRegistrationPayload([
        'account_type' => $type,
        'email' => $email,
        'company_name' => 'Dormant Co',
    ]))->assertSessionHasErrors(['account_type' => 'Carbon accounts are coming soon and cannot be registered yet.']);

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with(['carbon_developer', 'carbon_buyer']);

it('shows carbon account types as disabled "Coming soon" cards by default', function () {
    $response = $this->get(route('register'))->assertOk();

    foreach (['carbon_developer', 'carbon_buyer'] as $type) {
        $response->assertSee('data-coming-soon="'.$type.'"', false);
        $response->assertDontSee('name="account_type" value="'.$type.'"', false);
    }

    foreach (['buyer', 'supplier', 'processor', 'artisan', 'logistics_partner'] as $type) {
        $response->assertSee('name="account_type" value="'.$type.'"', false);
    }

    $response->assertSee('Coming soon');
});

it('keeps existing carbon role holders working when carbon signup is disabled', function () {
    config(['timber.signup.carbon_enabled' => false]);
    $user = User::factory()->create();
    $user->assignRole('carbon_buyer');

    expect($user->fresh()->hasRole('carbon_buyer'))->toBeTrue();
    $this->actingAs($user)->get(route('register'))->assertRedirect();
});
