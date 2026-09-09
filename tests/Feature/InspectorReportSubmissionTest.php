<?php

use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Offline-capable field data capture for inspectors (blueprint §45-46).
 * OfflineQueue itself is client-side JS with no server test surface; these
 * tests instead cover the server endpoint it replays against — including
 * the "delayed offline sync" scenario where the POST arrives well after the
 * device queued it, by which time the inspection's state may have moved on.
 */
function makeInspectorUser(array $attributes = []): array
{
    $user = User::factory()->create($attributes + ['email' => 'field-inspector'.uniqid().'@example.com']);
    $user->assignRole('compliance_officer');

    $inspector = Inspector::create([
        'user_id' => $user->getKey(),
        'status' => 'active',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
    ]);

    return [$user, $inspector];
}

function makeInspection(Inspector $inspector, array $overrides = []): Inspection
{
    return Inspection::create(array_merge([
        'inspector_id' => $inspector->getKey(),
        'inspection_type' => 'pre_shipment',
    ], $overrides));
}

function validReportPayload(): array
{
    return [
        'performed_at' => now()->toDateTimeString(),
        'location' => 'Douala depot, Yard 3',
        'observed_quantity' => 120.5,
        'species_findings' => 'Consistent with declared species.',
        'quality_findings' => 'No visible defects.',
        'packaging_findings' => 'Bundled and strapped correctly.',
        'result' => 'pass',
        'inspector_notes' => 'Clean inspection, no concerns.',
    ];
}

/* ---------------------------------------------------------------- access */

it('shows the report form to the assigned inspector', function () {
    [$user, $inspector] = makeInspectorUser();
    $inspection = makeInspection($inspector);

    $this->actingAs($user)
        ->get(route('inspector.inspections.report.edit', $inspection))
        ->assertOk()
        ->assertSee('Inspection report');
});

it('returns 403 for a different inspector', function () {
    [, $mine] = makeInspectorUser();
    [$other] = makeInspectorUser();
    $inspection = makeInspection($mine);

    $this->actingAs($other)
        ->get(route('inspector.inspections.report.edit', $inspection))
        ->assertForbidden();

    $this->actingAs($other)
        ->postJson(route('inspector.inspections.report.store', $inspection), validReportPayload())
        ->assertForbidden();
});

it('returns 403 for a guest', function () {
    [, $inspector] = makeInspectorUser();
    $inspection = makeInspection($inspector);

    $this->get(route('inspector.inspections.report.edit', $inspection))
        ->assertRedirect(); // auth middleware -> login redirect
});

/* ------------------------------------------------------------ submission */

it('validates and finalises a real inspection when submitted online', function () {
    [$user, $inspector] = makeInspectorUser();
    $inspection = makeInspection($inspector);

    $response = $this->actingAs($user)
        ->postJson(route('inspector.inspections.report.store', $inspection), validReportPayload());

    $response->assertOk()->assertJson(['message' => 'Inspection report finalised.']);

    $inspection->refresh();
    expect($inspection->finalised_at)->not->toBeNull();
    expect($inspection->result)->toBe('pass');
    expect($inspection->digital_signature)->not->toBeNull();
});

it('rejects an invalid submission with a clear validation error, not a 500', function () {
    [$user, $inspector] = makeInspectorUser();
    $inspection = makeInspection($inspector);

    $payload = validReportPayload();
    unset($payload['performed_at']);
    $payload['result'] = 'not-a-real-result';

    $response = $this->actingAs($user)
        ->postJson(route('inspector.inspections.report.store', $inspection), $payload);

    $response->assertStatus(422);
    $errors = $response->json('errors');
    expect($errors)->toHaveKey('performed_at');
    expect($errors)->toHaveKey('result');

    $inspection->refresh();
    expect($inspection->finalised_at)->toBeNull();
});

it('rejects a delayed offline sync against an inspection that was finalised in the meantime', function () {
    [$user, $inspector] = makeInspectorUser();
    $inspection = makeInspection($inspector);

    // Simulate the inspection having already been finalised by the time a
    // submission queued while offline finally reaches the server (e.g. a
    // second device synced first, or staff finalised it another way).
    $inspection->fill(validReportPayload());
    $inspection->save();
    $inspection->finalise();

    $response = $this->actingAs($user)
        ->postJson(route('inspector.inspections.report.store', $inspection), validReportPayload());

    $response->assertStatus(422);
    $response->assertJson(['code' => 'already_finalised']);
});
