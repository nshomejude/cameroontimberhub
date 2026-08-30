<?php

use App\Models\Inspection;
use App\Models\InspectionAmendment;
use App\Models\Inspector;
use App\Models\TimberLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEligibleInspector(): Inspector
{
    return Inspector::create([
        'user_id' => User::factory()->create()->id,
        'organisation_name' => 'Test Inspection Co',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
        'status' => 'active',
        'coverage_regions' => ['Centre'],
        'inspection_categories' => ['quality_grade'],
    ]);
}

test('scopeEligible excludes inspectors missing verification, agreement, or active status', function () {
    $eligible = makeEligibleInspector();

    $missingIdentity = Inspector::create([
        'user_id' => User::factory()->create()->id,
        'agreement_accepted_at' => now(),
        'status' => 'active',
    ]);

    $missingAgreement = Inspector::create([
        'user_id' => User::factory()->create()->id,
        'identity_verified_at' => now(),
        'status' => 'active',
    ]);

    $notActive = Inspector::create([
        'user_id' => User::factory()->create()->id,
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
        'status' => 'pending',
    ]);

    $eligibleIds = Inspector::eligible()->pluck('id')->all();

    expect($eligibleIds)->toContain($eligible->id)
        ->not->toContain($missingIdentity->id)
        ->not->toContain($missingAgreement->id)
        ->not->toContain($notActive->id);

    expect($eligible->isEligible())->toBeTrue();
    expect($missingIdentity->isEligible())->toBeFalse();
});

test('finalising an inspection computes a digital signature and locks report content', function () {
    $inspector = makeEligibleInspector();

    $inspection = Inspection::create([
        'inspector_id' => $inspector->id,
        'inspection_type' => 'quality_grade',
        'result' => 'pass',
        'quality_findings' => 'Good grade throughout.',
    ]);

    expect($inspection->digital_signature)->toBeNull();
    expect($inspection->finalised_at)->toBeNull();

    $inspection->finalise();
    $inspection->refresh();

    expect($inspection->digital_signature)->not->toBeNull();
    expect($inspection->finalised_at)->not->toBeNull();

    expect(fn () => $inspection->update(['result' => 'fail']))
        ->toThrow(RuntimeException::class);

    $inspection->refresh();
    expect(fn () => $inspection->update(['quality_findings' => 'Changed after finalisation']))
        ->toThrow(RuntimeException::class);
});

test('amend() creates an InspectionAmendment and applies the change to a finalised report', function () {
    $inspector = makeEligibleInspector();
    $amender = User::factory()->create();

    $inspection = Inspection::create([
        'inspector_id' => $inspector->id,
        'inspection_type' => 'quality_grade',
        'result' => 'pass',
        'quality_findings' => 'Initial finding.',
    ]);

    $inspection->finalise();
    $inspection->refresh();

    $amendment = $inspection->amend(
        ['quality_findings' => 'Corrected finding after re-review.'],
        $amender,
        'Original observation contained a transcription error.'
    );

    expect($amendment)->toBeInstanceOf(InspectionAmendment::class);
    expect($amendment->inspection_id)->toBe($inspection->id);
    expect($amendment->amended_by)->toBe($amender->id);
    expect($amendment->changes['quality_findings']['after'])->toBe('Corrected finding after re-review.');

    $inspection->refresh();
    expect($inspection->quality_findings)->toBe('Corrected finding after re-review.');
    expect($inspection->amendments()->count())->toBe(1);
});

test('finalising a passing inspection updates the related TimberLot inspection_status', function () {
    $inspector = makeEligibleInspector();
    $lot = TimberLot::factory()->create(['inspection_status' => 'scheduled']);

    $inspection = Inspection::create([
        'inspector_id' => $inspector->id,
        'timber_lot_id' => $lot->id,
        'inspection_type' => 'quality_grade',
        'result' => 'pass',
    ]);

    $inspection->finalise();

    $lot->refresh();
    expect($lot->inspection_status)->toBe('passed');
});
