<?php

use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Models\CarbonProject;
use App\Models\Company;

function carbonProjectShowCompany(array $attributes = []): Company
{
    return Company::factory()->publiclyVisible()->create(array_merge([
        'type' => OrganisationType::CarbonDeveloper,
    ], $attributes));
}

it('shows an active project from a publicly visible carbon-developer company', function () {
    $company = carbonProjectShowCompany(['legal_name' => 'Bamenda Forest Restorers SARL']);
    $project = CarbonProject::factory()->active()->for($company)->create([
        'name' => 'Bamenda Highlands Restoration',
        'region' => 'North West',
        'area_hectares' => 1250,
        'estimated_credits_per_year' => 4200,
        'description' => 'Restoring degraded highland forest cover.',
    ]);

    $response = $this->get(route('carbon-projects.show', $project));

    $response->assertOk();
    $response->assertSee($project->name);
    $response->assertSee('North West');
    $response->assertSee($company->name);
});

it('404s for a draft/inactive project', function () {
    $company = carbonProjectShowCompany();
    $draft = CarbonProject::factory()->for($company)->create([
        'name' => 'Draft Project',
        'status' => ProductStatus::Draft,
    ]);

    $response = $this->get(route('carbon-projects.show', $draft));

    $response->assertNotFound();
});

it('404s for a project belonging to a non-publicly-visible company', function () {
    $company = Company::factory()->create([
        'type' => OrganisationType::CarbonDeveloper,
    ]); // not publiclyVisible: missing logo/species/contacts/badge
    $project = CarbonProject::factory()->active()->for($company)->create(['name' => 'Hidden Company Project']);

    $response = $this->get(route('carbon-projects.show', $project));

    $response->assertNotFound();
});

it('links the directory cards to the correct detail URL', function () {
    $company = carbonProjectShowCompany(['legal_name' => 'Douala Mangrove Carbon SARL']);
    $project = CarbonProject::factory()->active()->for($company)->create(['name' => 'Douala Mangrove Restoration']);

    $response = $this->get('/carbon-projects');

    $response->assertOk();
    $response->assertSee(route('carbon-projects.show', $project), false);
});
