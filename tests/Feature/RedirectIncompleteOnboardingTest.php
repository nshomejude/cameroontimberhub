<?php

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyDocument;
use App\Models\CompanyGallery;
use App\Models\Species;
use App\Models\User;
use App\Models\VerificationRequest;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ownerFor(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('redirects a company owner with an incomplete checklist from the dashboard to the onboarding checklist', function () {
    $company = Company::factory()->create();

    $this->actingAs(ownerFor($company));

    $this->get('/dashboard')
        ->assertRedirect(route('filament.exporter.pages.onboarding-checklist'));
});

it('does not redirect a company owner whose checklist is complete', function () {
    $company = Company::factory()->create(['profile_completion' => 100]);

    CompanyContact::factory()->create(['company_id' => $company->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'gallery/test.jpg']);
    CompanyDocument::factory()->create(['company_id' => $company->id]);
    VerificationRequest::factory()->create(['company_id' => $company->id]);
    $company->species()->attach(Species::factory()->create());

    $this->actingAs(ownerFor($company));

    $this->get('/dashboard')->assertOk();
});

it('never redirects when already visiting the onboarding checklist page, avoiding a loop', function () {
    $company = Company::factory()->create();

    $this->actingAs(ownerFor($company));

    $this->get(route('filament.exporter.pages.onboarding-checklist'))->assertOk();
});

it('does not redirect on a second visit within the same session once the redirect has already been shown', function () {
    $company = Company::factory()->create();

    $this->actingAs(ownerFor($company));

    $this->get('/dashboard')->assertRedirect(route('filament.exporter.pages.onboarding-checklist'));

    // Second hit of the onboarding page itself (simulating the user landing
    // there), then a further dashboard hit should not redirect again since
    // the session flag is now set.
    $this->get(route('filament.exporter.pages.onboarding-checklist'))->assertOk();
    $this->get('/dashboard')->assertOk();
});

it('is unaffected by a user with no company (route already blocks them before the middleware matters)', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertForbidden();
});

it('does not error for an unauthenticated request', function () {
    $this->get('/dashboard')->assertRedirect(route('filament.exporter.auth.login'));
});
