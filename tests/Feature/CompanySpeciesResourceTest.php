<?php

use App\Filament\Exporter\Resources\CompanySpecies\Pages\CreateCompanySpecies;
use App\Filament\Exporter\Resources\CompanySpecies\Pages\EditCompanySpecies;
use App\Models\CompanySpecies;
use App\Models\Company;
use App\Models\PriceObservation;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

function companySpeciesMember(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);

    return [$user, $company];
}

test('a company member can create a species-handled row scoped to their own company', function () {
    [$user, $company] = companySpeciesMember();
    $species = Species::factory()->create(['is_published' => true]);

    $this->actingAs($user);

    Livewire::test(CreateCompanySpecies::class)
        ->fillForm([
            'species_id' => $species->getKey(),
            'unit' => 'm3',
            'basis' => 'fob',
            'region' => 'Douala',
            'price_amount' => 250,
            'price_currency' => 'USD',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $row = CompanySpecies::first();

    expect($row)->not->toBeNull()
        ->and($row->company_id)->toBe($company->getKey())
        ->and($row->species_id)->toBe($species->getKey());

    expect(PriceObservation::where('source', 'listed')->count())->toBe(1);
});

test('a company only sees its own species rows', function () {
    [$userA, $companyA] = companySpeciesMember();
    $companyB = Company::factory()->create();

    CompanySpecies::create(['company_id' => $companyA->getKey(), 'species_id' => Species::factory()->create(['common_name' => 'Ayous Mine'])->getKey()]);
    CompanySpecies::create(['company_id' => $companyB->getKey(), 'species_id' => Species::factory()->create(['common_name' => 'Sapele Theirs'])->getKey()]);

    $res = $this->actingAs($userA)->get('/dashboard/company-species');
    $res->assertOk();
    $res->assertSee('Ayous Mine');
    $res->assertDontSee('Sapele Theirs');
});

test('another company\'s row edit page 404s', function () {
    [$userA] = companySpeciesMember();
    $companyB = Company::factory()->create();
    $rowB = CompanySpecies::create([
        'company_id' => $companyB->getKey(),
        'species_id' => Species::factory()->create()->getKey(),
    ]);

    $this->actingAs($userA)
        ->get('/dashboard/company-species/'.$rowB->getKey().'/edit')
        ->assertNotFound();
});

test('a company member can edit their own row', function () {
    [$user, $company] = companySpeciesMember();
    $row = CompanySpecies::create([
        'company_id' => $company->getKey(),
        'species_id' => Species::factory()->create()->getKey(),
    ]);

    $this->actingAs($user);

    Livewire::test(EditCompanySpecies::class, ['record' => $row->getKey()])
        ->fillForm(['region' => 'Yaoundé'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($row->refresh()->region)->toBe('Yaoundé');
});
