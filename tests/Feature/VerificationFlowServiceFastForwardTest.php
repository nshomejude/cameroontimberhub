<?php

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\VerificationFlowService;

it('jumps a verification directly to a target stage in one labelled checkpoint', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $flow = app(VerificationFlowService::class);

    $verification = $flow->open($company);

    $result = $flow->fastForward($verification, VerificationStage::Verified, $actor, 'Mirrored from verification_requests approval');

    expect($result->stage)->toBe(VerificationStage::Verified);
    expect($result->checkpoints)->toHaveCount(1);
    expect($result->checkpoints->first()->status)->toBe(CheckpointStatus::Approved);
    expect($result->checkpoints->first()->notes)->toBe('Mirrored from verification_requests approval');
});

it('refuses to fast forward a terminal verification', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $flow = app(VerificationFlowService::class);

    $verification = $flow->open($company);
    $flow->fastForward($verification, VerificationStage::Published, $actor, 'terminal');

    expect(fn () => $flow->fastForward($verification->fresh(), VerificationStage::Verified, $actor, 'should fail'))
        ->toThrow(RuntimeException::class);
});

it('does not disturb Products own step-by-step FORWARD flow', function () {
    $product = Product::factory()->create();
    $actor = User::factory()->create();
    $flow = app(VerificationFlowService::class);

    $verification = $flow->open($product);
    $flow->approve($verification, $actor);

    expect($verification->fresh()->stage)->toBe(VerificationStage::CompanyInfo);
});
