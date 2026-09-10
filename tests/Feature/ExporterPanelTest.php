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

function exporterFor(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('lets a company member reach the dashboard and edit their own company', function () {
    // profile_completion: 100 plus every onboarding-checklist ingredient so
    // this request isn't intercepted by RedirectIncompleteOnboarding -- this
    // test is about company edit access, not the onboarding flow itself.
    $company = Company::factory()->publiclyVisible()->create(['profile_completion' => 100]);
    CompanyContact::factory()->create(['company_id' => $company->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'gallery/test.jpg']);
    CompanyDocument::factory()->create(['company_id' => $company->id]);
    VerificationRequest::factory()->create(['company_id' => $company->id]);
    $company->species()->attach(Species::factory()->create());

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

it('lets a company member reach the fleet vehicle and driver resources', function () {
    $company = Company::factory()->publiclyVisible()->create(['profile_completion' => 100]);
    CompanyContact::factory()->create(['company_id' => $company->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'gallery/test.jpg']);
    CompanyDocument::factory()->create(['company_id' => $company->id]);
    VerificationRequest::factory()->create(['company_id' => $company->id]);
    $company->species()->attach(Species::factory()->create());

    $this->actingAs(exporterFor($company));

    $this->get('/dashboard/vehicles')->assertOk();
    $this->get('/dashboard/vehicles/create')->assertOk();
    $this->get('/dashboard/drivers')->assertOk();
    $this->get('/dashboard/drivers/create')->assertOk();
});
