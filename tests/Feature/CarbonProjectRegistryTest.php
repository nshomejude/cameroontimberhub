<?php

use App\Enums\CarbonRegistryStatus;
use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Filament\Exporter\Resources\CarbonProjects\Pages\EditCarbonProject;
use App\Models\CarbonProject;
use App\Models\Company;
use App\Models\User;
use App\Services\CarbonProjectQrCodeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

$validPolygon = [
    'type' => 'Polygon',
    'coordinates' => [[[11.5, 3.8], [11.6, 3.8], [11.6, 3.9], [11.5, 3.9], [11.5, 3.8]]],
];

test('a carbon project gets a CTH-CARB public id and 64-char token on create, both unique', function () {
    $a = CarbonProject::factory()->create();
    $b = CarbonProject::factory()->create();

    expect($a->public_id)->toMatch('/^CTH-CARB-\d{5}$/')
        ->and($b->public_id)->toMatch('/^CTH-CARB-\d{5}$/')
        ->and($a->public_id)->not->toBe($b->public_id)
        ->and(strlen($a->verification_token))->toBe(64)
        ->and($a->verification_token)->not->toBe($b->verification_token)
        ->and($a->registry_status)->toBe(CarbonRegistryStatus::Draft);
});

test('boundary accepts a valid GeoJSON polygon and rejects an invalid structure on write', function () use ($validPolygon) {
    $project = CarbonProject::factory()->create(['boundary' => $validPolygon]);
    expect($project->fresh()->boundary)->toBe($validPolygon);

    expect(fn () => CarbonProject::factory()->create(['boundary' => ['type' => 'Point', 'coordinates' => [1, 2]]]))
        ->toThrow(InvalidArgumentException::class);
});

test('CarbonRegistryStatus transition table allows legal moves and blocks illegal ones', function () {
    expect(CarbonRegistryStatus::Draft->canTransitionTo(CarbonRegistryStatus::Submitted))->toBeTrue()
        ->and(CarbonRegistryStatus::Submitted->canTransitionTo(CarbonRegistryStatus::UnderReview))->toBeTrue()
        ->and(CarbonRegistryStatus::UnderReview->canTransitionTo(CarbonRegistryStatus::Registered))->toBeTrue()
        ->and(CarbonRegistryStatus::Registered->canTransitionTo(CarbonRegistryStatus::Active))->toBeTrue()
        ->and(CarbonRegistryStatus::Active->canTransitionTo(CarbonRegistryStatus::Suspended))->toBeTrue()
        ->and(CarbonRegistryStatus::Suspended->canTransitionTo(CarbonRegistryStatus::Active))->toBeTrue()
        ->and(CarbonRegistryStatus::Draft->canTransitionTo(CarbonRegistryStatus::Registered))->toBeFalse()
        ->and(CarbonRegistryStatus::Draft->canTransitionTo(CarbonRegistryStatus::Active))->toBeFalse()
        ->and(CarbonRegistryStatus::Rejected->canTransitionTo(CarbonRegistryStatus::Active))->toBeFalse();
});

test('transitionTo throws on an illegal transition and persists a legal one', function () {
    $project = CarbonProject::factory()->create();

    expect(fn () => $project->transitionTo(CarbonRegistryStatus::Active))
        ->toThrow(RuntimeException::class);

    $project->transitionTo(CarbonRegistryStatus::Submitted);
    expect($project->fresh()->registry_status)->toBe(CarbonRegistryStatus::Submitted);
});

test('GET /verify/carbon/{publicId} renders for registered/active projects without the numeric id', function () {
    $company = Company::factory()->create([
        'type' => OrganisationType::CarbonDeveloper,
        'status' => CompanyStatus::Verified,
        'legal_name' => 'Green Congo Basin SARL',
    ]);
    $project = CarbonProject::factory()->for($company)->create([
        'name' => 'Dja reforestation block',
        'registry_status' => CarbonRegistryStatus::Registered,
    ]);

    $response = $this->get('/verify/carbon/'.$project->public_id);

    $response->assertOk()
        ->assertSee('Dja reforestation block')
        ->assertSee('Green Congo Basin SARL')
        ->assertSee('Registered')
        ->assertDontSee('"id":'.$project->getKey());
});

test('a draft carbon project and an unknown id both 404 on the verify page', function () {
    $draft = CarbonProject::factory()->create(['registry_status' => CarbonRegistryStatus::Draft]);

    $this->get('/verify/carbon/'.$draft->public_id)->assertNotFound();
    $this->get('/verify/carbon/CTH-CARB-99999')->assertNotFound();
});

test('the QR service returns svg markup', function () {
    $project = CarbonProject::factory()->create(['registry_status' => CarbonRegistryStatus::Active]);

    $svg = app(CarbonProjectQrCodeService::class)->svg($project);

    expect($svg)->toContain('<svg')->toContain('</svg>');
});

test('a carbon_developer role member can edit boundary and submit for review; submit does draft to submitted', function () use ($validPolygon) {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));

    $company = Company::factory()->create(['type' => OrganisationType::CarbonDeveloper]);
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner']);
    $user->assignRole('carbon_developer');

    $project = CarbonProject::factory()->for($company)->create(['registry_status' => CarbonRegistryStatus::Draft]);

    $this->actingAs($user);

    Livewire::test(EditCarbonProject::class, ['record' => $project->getKey()])
        ->fillForm(['boundary' => json_encode($validPolygon)])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->fresh()->boundary)->toBe($validPolygon);

    Livewire::test(EditCarbonProject::class, ['record' => $project->getKey()])
        ->callAction('submitForReview');

    expect($project->fresh()->registry_status)->toBe(CarbonRegistryStatus::Submitted);
});
