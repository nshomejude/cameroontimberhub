<?php

use App\Filament\Pages\InspectorWorkspace;
use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\TimberLot;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * An inspector who can actually reach the admin panel: User::canAccessPanel()
 * gates the entire admin panel to a fixed staff-role allowlist (see
 * app/Models/User.php), which has no narrower "inspector" role today, so a
 * real inspector needing panel access carries a staff role too -- here
 * compliance_officer, matching InspectorResource/InspectionResource's own
 * compliance.manage gate.
 */
function inspectorUser(array $attributes = []): array
{
    $user = User::factory()->create($attributes + ['email' => 'inspector'.uniqid().'@example.com']);
    $user->assignRole('compliance_officer');

    $inspector = Inspector::create([
        'user_id' => $user->getKey(),
        'status' => 'active',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
    ]);

    return [$user, $inspector];
}

function inspectionFor(Inspector $inspector, array $overrides = []): Inspection
{
    return Inspection::create(array_merge([
        'inspector_id' => $inspector->getKey(),
        'inspection_type' => 'pre_shipment',
    ], $overrides));
}

/* --------------------------------------------------------------- access */

it('blocks a plain user with no inspector profile and no compliance permission', function () {
    $plain = User::factory()->create();

    $this->actingAs($plain)->get(InspectorWorkspace::getUrl())->assertForbidden();
});

it('lets a user with an inspector profile reach the workspace', function () {
    [$user] = inspectorUser();

    $this->actingAs($user)->get(InspectorWorkspace::getUrl())->assertOk();
});

it('lets compliance staff reach the workspace even without an inspector profile', function () {
    $officer = User::factory()->create();
    $officer->assignRole('admin');

    $this->actingAs($officer)->get(InspectorWorkspace::getUrl())->assertOk();
});

/* -------------------------------------------------------------- content */

it('shows scheduled, in-progress and finalised inspections in their own sections', function () {
    [$user, $inspector] = inspectorUser();
    $lot = TimberLot::factory()->create(['lot_number' => 'LOT-MINE-0001']);

    inspectionFor($inspector, [
        'timber_lot_id' => $lot->getKey(),
        'scheduled_for' => now()->addDay(),
    ]);

    $response = $this->actingAs($user)->get(InspectorWorkspace::getUrl());

    $response->assertOk()->assertSee('LOT-MINE-0001');
});

it('shows a graceful empty state for an inspector with no assignments', function () {
    [$user] = inspectorUser();

    $this->actingAs($user)->get(InspectorWorkspace::getUrl())
        ->assertOk()
        ->assertSee('You have no scheduled inspections right now.')
        ->assertSee('You have no inspections in progress.')
        ->assertSee('You have not finalised any inspection reports yet.');
});

/* ---------------------------------------------------------------- scoping */

it('never shows one inspectors assignments to a different inspector', function () {
    [$mine, $myInspector] = inspectorUser();
    [$theirs, $theirInspector] = inspectorUser();

    $myLot = TimberLot::factory()->create(['lot_number' => 'LOT-MINE-9001']);
    $theirLot = TimberLot::factory()->create(['lot_number' => 'LOT-THEIRS-9001']);

    inspectionFor($myInspector, ['timber_lot_id' => $myLot->getKey(), 'scheduled_for' => now()->addDay()]);
    inspectionFor($theirInspector, ['timber_lot_id' => $theirLot->getKey(), 'scheduled_for' => now()->addDay()]);

    $response = $this->actingAs($mine)->get(InspectorWorkspace::getUrl());

    $response->assertOk()
        ->assertSee('LOT-MINE-9001')
        ->assertDontSee('LOT-THEIRS-9001');
});
