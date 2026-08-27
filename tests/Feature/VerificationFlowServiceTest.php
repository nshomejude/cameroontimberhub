<?php

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\User;
use App\Models\Verification;
use App\Services\VerificationFlowService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->service = app(VerificationFlowService::class);
});

it('opens a verification at the registered stage for an entity with none yet', function () {
    $product = Product::factory()->create();

    $verification = $this->service->open($product);

    expect($verification->stage)->toBe(VerificationStage::Registered)
        ->and($verification->entity->is($product))->toBeTrue();
});

it('returns the existing open verification instead of creating a second one', function () {
    $product = Product::factory()->create();

    $first = $this->service->open($product);
    $second = $this->service->open($product);

    expect($second->id)->toBe($first->id);
});

it('advances through each stage in the defined sequence on approval', function () {
    $verification = $this->service->open(Product::factory()->create());
    $officer = User::factory()->create();

    $sequence = [
        VerificationStage::CompanyInfo,
        VerificationStage::BusinessDocs,
        VerificationStage::IdentityKyc,
        VerificationStage::ForestryLegalDocs,
        VerificationStage::ComplianceReview,
        VerificationStage::Verified,
    ];

    foreach ($sequence as $expected) {
        $verification = $this->service->approve($verification, $officer);
        expect($verification->stage)->toBe($expected);
    }
});

it('rejects a compliance_review checkpoint with a reason, moving the verification to rejected', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::ComplianceReview]);
    $officer = User::factory()->create();

    $verification = $this->service->reject($verification, 'SIGIF permit could not be validated.', $officer);

    expect($verification->stage)->toBe(VerificationStage::Rejected)
        ->and($verification->checkpoints()->latest('id')->first()->status)->toBe(CheckpointStatus::Rejected)
        ->and($verification->checkpoints()->latest('id')->first()->notes)->toBe('SIGIF permit could not be validated.');
});

it('requires a reason to reject', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::ComplianceReview]);

    expect(fn () => $this->service->reject($verification, '', User::factory()->create()))
        ->toThrow(ValidationException::class);
});

it('records needs_more_info at the current stage without advancing or regressing it', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::IdentityKyc]);
    $officer = User::factory()->create();

    $verification = $this->service->requestMoreInfo($verification, 'Please upload a clearer ID scan.', $officer);

    expect($verification->stage)->toBe(VerificationStage::IdentityKyc)
        ->and($verification->needsMoreInfo())->toBeTrue();
});

it('clears needs_more_info and stays at the same stage when resubmitted, ready for the next approval', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::IdentityKyc]);
    $officer = User::factory()->create();
    $verification = $this->service->requestMoreInfo($verification, 'Blurry scan.', $officer);

    $verification = $this->service->resubmit($verification);

    expect($verification->stage)->toBe(VerificationStage::IdentityKyc)
        ->and($verification->needsMoreInfo())->toBeFalse();
});

it('publishes a verified entity, stamping published_at', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::Verified]);
    $officer = User::factory()->create();

    $verification = $this->service->publish($verification, $officer);

    expect($verification->stage)->toBe(VerificationStage::Published)
        ->and($verification->published_at)->not->toBeNull();
});

it('refuses to publish a verification that has not reached verified', function () {
    $verification = Verification::factory()->create(['stage' => VerificationStage::BusinessDocs]);

    expect(fn () => $this->service->publish($verification, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('refuses to approve a terminal (rejected or published) verification', function () {
    $rejected = Verification::factory()->create(['stage' => VerificationStage::Rejected]);

    expect(fn () => $this->service->approve($rejected, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});
