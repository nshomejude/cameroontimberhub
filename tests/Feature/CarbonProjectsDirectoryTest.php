<?php

use App\Enums\OrganisationType;
use App\Models\CarbonProject;
use App\Models\Company;

function carbonDeveloperCompany(array $attributes = []): Company
{
    return Company::factory()->publiclyVisible()->create(array_merge([
        'type' => OrganisationType::CarbonDeveloper,
    ], $attributes));
}

it('renders the carbon projects directory', function () {
    $response = $this->get('/carbon-projects');

    $response->assertOk();
});

it('shows an active project from a publicly visible carbon-developer company', function () {
    $company = carbonDeveloperCompany(['legal_name' => 'Yaounde Reforestation SARL']);
    $project = CarbonProject::factory()->active()->for($company)->create(['name' => 'Yaounde Reforestation Belt']);

    $response = $this->get('/carbon-projects');

    $response->assertOk();
    $response->assertSee($project->name);
    $response->assertSee($company->name);
});

it('does not show a draft project', function () {
    $company = carbonDeveloperCompany();
    $draft = CarbonProject::factory()->for($company)->create(['name' => 'Draft Only Project', 'status' => \App\Enums\ProductStatus::Draft]);

    $response = $this->get('/carbon-projects');

    $response->assertOk();
    $response->assertDontSee($draft->name);
});

it('does not show a project from a non-publicly-visible company', function () {
    $company = Company::factory()->create([
        'type' => OrganisationType::CarbonDeveloper,
    ]); // not publiclyVisible: no logo/species/contacts/badge
    $project = CarbonProject::factory()->active()->for($company)->create(['name' => 'Hidden Company Project']);

    $response = $this->get('/carbon-projects');

    $response->assertOk();
    $response->assertDontSee($project->name);
});

it('does not show a project from a non-carbon-developer company', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Supplier]);
    $project = CarbonProject::factory()->active()->for($company)->create(['name' => 'Wrong Type Project']);

    $response = $this->get('/carbon-projects');

    $response->assertOk();
    $response->assertDontSee($project->name);
});

it('filters by project_type', function () {
    $company = carbonDeveloperCompany();
    $reforestation = CarbonProject::factory()->active()->for($company)->create([
        'name' => 'Reforestation Match',
        'project_type' => 'reforestation',
    ]);
    $agroforestry = CarbonProject::factory()->active()->for($company)->create([
        'name' => 'Agroforestry NonMatch',
        'project_type' => 'agroforestry',
    ]);

    $response = $this->get('/carbon-projects?project_type[]=reforestation');

    $response->assertOk();
    $response->assertSee($reforestation->name);
    $response->assertDontSee($agroforestry->name);
});

it('links to the carbon projects page from the nav dropdown', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee(route('carbon-projects'), false);
});
