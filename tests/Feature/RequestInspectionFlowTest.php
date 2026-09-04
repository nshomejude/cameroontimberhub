<?php

use App\Filament\Exporter\Resources\InspectionRequests\Pages\CreateInspectionRequest;
use App\Filament\Exporter\Resources\InspectionRequests\Pages\ListInspectionRequests;
use App\Models\Company;
use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\TimberLot;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

function makeInspectionRequestCompanyUser(?string $region = 'Centre'): array
{
    $company = Company::factory()->create(['region' => $region]);
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return [$user, $company];
}

function makeInspectionRequestEligibleInspector(array $regions, array $categories): Inspector
{
    return Inspector::create([
        'user_id' => User::factory()->create()->id,
        'organisation_name' => 'Test Inspection Co',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
        'status' => 'active',
        'coverage_regions' => $regions,
        'inspection_categories' => $categories,
    ]);
}

test('a company can request an inspection for its own timber lot', function () {
    [$user, $company] = makeInspectionRequestCompanyUser();
    $lot = TimberLot::factory()->create(['company_id' => $company->getKey(), 'origin_region' => 'Centre']);

    $this->actingAs($user);

    Livewire::test(CreateInspectionRequest::class)
        ->fillForm([
            'timber_lot_id' => $lot->id,
            'inspection_type' => 'quality_grade',
            'scheduled_for' => now()->addWeek()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $inspection = Inspection::first();

    expect($inspection)->not->toBeNull()
        ->and($inspection->timber_lot_id)->toBe($lot->id)
        ->and($inspection->inspection_type)->toBe('quality_grade');
});

test('a company cannot request an inspection for another company\'s timber lot', function () {
    [$user, $company] = makeInspectionRequestCompanyUser();
    $otherCompany = Company::factory()->create();
    $foreignLot = TimberLot::factory()->create(['company_id' => $otherCompany->getKey()]);

    $this->actingAs($user);

    Livewire::test(CreateInspectionRequest::class)
        ->fillForm([
            'timber_lot_id' => $foreignLot->id,
            'inspection_type' => 'quality_grade',
            'scheduled_for' => now()->addWeek()->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['timber_lot_id']);

    expect(Inspection::count())->toBe(0);
});

test('an eligible inspector matching region and category is auto-assigned', function () {
    [$user, $company] = makeInspectionRequestCompanyUser();
    $lot = TimberLot::factory()->create(['company_id' => $company->getKey(), 'origin_region' => 'Centre']);

    $inspector = makeInspectionRequestEligibleInspector(['Centre'], ['quality_grade']);
    // A non-matching inspector (wrong category) should never be picked.
    makeInspectionRequestEligibleInspector(['Centre'], ['moisture']);

    $this->actingAs($user);

    Livewire::test(CreateInspectionRequest::class)
        ->fillForm([
            'timber_lot_id' => $lot->id,
            'inspection_type' => 'quality_grade',
            'scheduled_for' => now()->addWeek()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $inspection = Inspection::first();

    expect($inspection->inspector_id)->toBe($inspector->id);
});

test('the inspector with fewest pending inspections is picked when multiple match', function () {
    [$user, $company] = makeInspectionRequestCompanyUser();
    $lot = TimberLot::factory()->create(['company_id' => $company->getKey(), 'origin_region' => 'Centre']);

    $busyInspector = makeInspectionRequestEligibleInspector(['Centre'], ['quality_grade']);
    $freeInspector = makeInspectionRequestEligibleInspector(['Centre'], ['quality_grade']);

    Inspection::create([
        'inspector_id' => $busyInspector->id,
        'inspection_type' => 'quality_grade',
    ]);

    $this->actingAs($user);

    Livewire::test(CreateInspectionRequest::class)
        ->fillForm([
            'timber_lot_id' => $lot->id,
            'inspection_type' => 'quality_grade',
            'scheduled_for' => now()->addWeek()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newInspection = Inspection::latest('id')->first();

    expect($newInspection->inspector_id)->toBe($freeInspector->id);
});

test('when no eligible inspector matches the request is still created unassigned and visible to staff', function () {
    [$user, $company] = makeInspectionRequestCompanyUser('Centre');
    $lot = TimberLot::factory()->create(['company_id' => $company->getKey(), 'origin_region' => 'Centre']);

    // An inspector exists but does not cover this region/category.
    makeInspectionRequestEligibleInspector(['South'], ['moisture']);

    $this->actingAs($user);

    Livewire::test(CreateInspectionRequest::class)
        ->fillForm([
            'timber_lot_id' => $lot->id,
            'inspection_type' => 'quality_grade',
            'scheduled_for' => now()->addWeek()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $inspection = Inspection::first();

    expect($inspection)->not->toBeNull()
        ->and($inspection->inspector_id)->toBeNull()
        ->and($inspection->timber_lot_id)->toBe($lot->id);
});

test('a company with no timber lots sees an empty state, not an error', function () {
    [$user, $company] = makeInspectionRequestCompanyUser();

    $response = $this->actingAs($user)->get('/dashboard/inspection-requests');

    $response->assertOk();
    $response->assertSee('No inspection requests yet');
});
