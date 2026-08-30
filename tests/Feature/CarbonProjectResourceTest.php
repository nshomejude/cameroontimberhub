<?php

use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Filament\Exporter\Resources\CarbonProjects\Pages\CreateCarbonProject;
use App\Filament\Exporter\Resources\CarbonProjects\Pages\EditCarbonProject;
use App\Models\CarbonProject;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

test('a carbon-developer company user can create a carbon project scoped to their own company', function () {
    $company = Company::factory()->create(['type' => OrganisationType::CarbonDeveloper]);
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($user);

    Livewire::test(CreateCarbonProject::class)
        ->fillForm([
            'name' => 'Ebo forest reforestation',
            'project_type' => 'reforestation',
            'status' => ProductStatus::Draft->value,
            'region' => 'Littoral',
            'area_hectares' => 1200,
            'estimated_credits_per_year' => 5000,
            'description' => 'Community-led reforestation.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = CarbonProject::first();

    expect($project)->not->toBeNull()
        ->and($project->company_id)->toBe($company->getKey())
        ->and($project->name)->toBe('Ebo forest reforestation');
});

test('a carbon-developer company user can edit their own carbon project', function () {
    $company = Company::factory()->create(['type' => OrganisationType::CarbonDeveloper]);
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);

    $project = CarbonProject::factory()->for($company)->create(['name' => 'Original name']);

    $this->actingAs($user);

    Livewire::test(EditCarbonProject::class, ['record' => $project->getKey()])
        ->fillForm(['name' => 'Updated name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->fresh()->name)->toBe('Updated name');
});

test('a company only sees its own carbon projects in the resource list, scoped to company_id', function () {
    $companyA = Company::factory()->create(['type' => OrganisationType::CarbonDeveloper]);
    $companyB = Company::factory()->create(['type' => OrganisationType::CarbonDeveloper]);

    $userA = User::factory()->create();
    $userA->companies()->attach($companyA, ['role' => 'owner']);

    CarbonProject::factory()->for($companyA)->create(['name' => 'Mine, visible']);
    CarbonProject::factory()->for($companyB)->create(['name' => 'Theirs, hidden']);

    $response = $this->actingAs($userA)->get('/dashboard/carbon-projects');

    $response->assertOk();
    $response->assertSee('Mine, visible');
    $response->assertDontSee('Theirs, hidden');
});

test('a non carbon-developer company cannot view the carbon projects resource', function () {
    $company = Company::factory()->create(['type' => OrganisationType::Supplier]);
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($user)
        ->get('/dashboard/carbon-projects')
        ->assertForbidden();
});
