<?php

use App\Enums\ConsentPurpose;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Consent;
use Illuminate\Database\Eloquent\Relations\MorphMany;

it('has a CompanyInquirySharing consent purpose accepted by the database CHECK constraint', function () {
    $inquiry = CompanyInquiry::factory()->for(Company::factory())->create();

    $consent = Consent::create([
        'subject_type' => CompanyInquiry::class,
        'subject_id' => $inquiry->id,
        'purpose' => ConsentPurpose::CompanyInquirySharing->value,
        'granted_at' => now(),
    ]);

    expect($consent->fresh())->not->toBeNull()
        ->and($consent->purpose)->toBe(ConsentPurpose::CompanyInquirySharing);
});

it('gives CompanyInquiry the HasConsents trait', function () {
    $inquiry = CompanyInquiry::factory()->for(Company::factory())->create();

    expect($inquiry->consents())->toBeInstanceOf(MorphMany::class)
        ->and($inquiry->hasActiveConsent(ConsentPurpose::CompanyInquirySharing))->toBeFalse();

    Consent::create([
        'subject_type' => CompanyInquiry::class,
        'subject_id' => $inquiry->id,
        'purpose' => ConsentPurpose::CompanyInquirySharing->value,
        'granted_at' => now(),
    ]);

    expect($inquiry->fresh()->hasActiveConsent(ConsentPurpose::CompanyInquirySharing))->toBeTrue();
});
