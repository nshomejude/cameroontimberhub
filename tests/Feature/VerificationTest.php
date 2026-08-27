<?php

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\Verification;
use App\Models\VerificationCheckpoint;
use Illuminate\Database\QueryException;

it('attaches a polymorphic verification to an owning entity', function () {
    $product = Product::factory()->create();

    $verification = Verification::create([
        'entity_type' => Product::class,
        'entity_id' => $product->id,
        'stage' => VerificationStage::Registered,
    ]);

    expect($verification->entity)->toBeInstanceOf(Product::class)
        ->and($verification->entity->is($product))->toBeTrue()
        ->and($product->fresh()->verification->is($verification))->toBeTrue();
});

it('defaults a new verification to the registered stage', function () {
    $verification = Verification::factory()->create();

    expect($verification->stage)->toBe(VerificationStage::Registered);
});

it('allows only one open (non-terminal) verification per entity', function () {
    $product = Product::factory()->create();
    Verification::factory()->for($product, 'entity')->create(['stage' => VerificationStage::CompanyInfo]);

    expect(fn () => Verification::factory()->for($product, 'entity')->create(['stage' => VerificationStage::Registered]))
        ->toThrow(QueryException::class);
});

it('records a checkpoint against a verification with a stage, status and reviewer', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::ComplianceReview]);

    $checkpoint = VerificationCheckpoint::create([
        'verification_id' => $verification->id,
        'stage' => VerificationStage::ComplianceReview,
        'status' => CheckpointStatus::NeedsMoreInfo,
        'notes' => 'SIGIF permit number is illegible, please re-upload.',
    ]);

    expect($checkpoint->verification->is($verification))->toBeTrue()
        ->and($verification->fresh()->checkpoints)->toHaveCount(1)
        ->and($verification->fresh()->checkpoints->first()->status)->toBe(CheckpointStatus::NeedsMoreInfo);
});

it('exposes whether a verification is currently blocked on needs_more_info without changing its stage', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::IdentityKyc]);
    VerificationCheckpoint::factory()->for($verification)->create([
        'stage' => VerificationStage::IdentityKyc,
        'status' => CheckpointStatus::NeedsMoreInfo,
    ]);

    expect($verification->fresh()->needsMoreInfo())->toBeTrue()
        ->and($verification->fresh()->stage)->toBe(VerificationStage::IdentityKyc);
});
