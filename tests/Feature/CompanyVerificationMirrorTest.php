<?php

use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\User;
use App\Models\Verification;
use App\Services\CompanyVerificationMirror;

it('opens a Verification row when a company is submitted for review', function () {
    $company = Company::factory()->create();

    app(CompanyVerificationMirror::class)->submitted($company);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification)->not->toBeNull();
    expect($verification->stage)->toBe(VerificationStage::Registered);
});

it('fast forwards the mirror to verified on approval, without touching badges', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $mirror = app(CompanyVerificationMirror::class);

    $mirror->submitted($company);
    $mirror->approved($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Verified);
});

it('fast forwards the mirror to rejected on rejection', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $mirror = app(CompanyVerificationMirror::class);

    $mirror->submitted($company);
    $mirror->rejected($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Rejected);
});

it('never throws even if no open mirror row exists yet', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();

    // approved() called without a prior submitted() — e.g. VerifyCompany's
    // direct-verify path, which never opens a VerificationRequest.
    app(CompanyVerificationMirror::class)->approved($company, $actor);

    expect(Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->exists())->toBeTrue();
});
