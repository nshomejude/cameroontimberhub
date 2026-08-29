<?php

use App\Enums\CompanyStatus;
use App\Enums\VerificationStage;
use App\Models\Company;
use App\Models\User;
use App\Models\Verification;

it('backfills a mirrored Verification row for every non-draft company at the right terminal stage', function () {
    User::factory()->create();
    $verified = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $rejected = Company::factory()->create(['status' => CompanyStatus::Rejected]);
    $draft = Company::factory()->create(['status' => CompanyStatus::Draft]);

    $this->artisan('companies:backfill-verification-mirror')->assertSuccessful();

    expect(Verification::where('entity_type', Company::class)->where('entity_id', $verified->id)->first()->stage)->toBe(VerificationStage::Verified);
    expect(Verification::where('entity_type', Company::class)->where('entity_id', $rejected->id)->first()->stage)->toBe(VerificationStage::Rejected);
    expect(Verification::where('entity_type', Company::class)->where('entity_id', $draft->id)->exists())->toBeFalse();
});

it('is idempotent', function () {
    User::factory()->create();
    Company::factory()->create(['status' => CompanyStatus::Verified]);

    $this->artisan('companies:backfill-verification-mirror')->assertSuccessful();
    $firstCount = Verification::count();
    $this->artisan('companies:backfill-verification-mirror')->assertSuccessful();

    expect(Verification::count())->toBe($firstCount);
});
