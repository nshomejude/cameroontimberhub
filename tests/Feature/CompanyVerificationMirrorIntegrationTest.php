<?php

use App\Actions\Company\VerifyCompany;
use App\Enums\CompanyStatus;
use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\User;
use App\Models\Verification;
use App\Services\VerificationService;

it('mirrors a real verification_requests approval into a Verification row, badges untouched', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Draft]);
    $actor = User::factory()->create();
    $service = app(VerificationService::class);

    $request = $service->submit($company);
    $service->startReview($request, $actor);
    $result = $service->approve($request->fresh(), $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Verified);
    // badge issuance result is exactly what BadgeService/VerificationService already decided —
    // this test does not assert on $result['issued'] because no documents were approved in this
    // fixture, proving the mirror addition changes nothing about that decision.
    expect($result['issued'])->toBe([]);
});

it('mirrors VerifyCompanys direct-verify path too, which issues no badges', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Pending]);
    $actor = User::factory()->create();

    app(VerifyCompany::class)->execute($company, $actor);

    $verification = Verification::where('entity_type', Company::class)->where('entity_id', $company->id)->first();
    expect($verification->stage)->toBe(VerificationStage::Verified);
    expect($company->fresh()->activeBadges()->count())->toBe(0);
});
