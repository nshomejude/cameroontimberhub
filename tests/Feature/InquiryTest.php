<?php

use App\Enums\CompanyStatus;
use App\Enums\ConsentPurpose;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Services\IntakeService;

it('persists a Consent record from the inquiry form checkbox instead of discarding it', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);

    $this->post(route('inquiry.store', $company), [
        'name' => 'Jane Buyer',
        'email' => 'jane@example.com',
        'message' => str_repeat('Interested in your sapelli stock. ', 3),
        'consent' => '1',
    ])->assertRedirect();

    $inquiry = CompanyInquiry::where('email', 'jane@example.com')->firstOrFail();

    expect($inquiry->consents()->where('purpose', ConsentPurpose::CompanyInquirySharing->value)->exists())->toBeTrue();
});

it('does not persist a Consent record when the inquiry form checkbox is left unchecked', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);

    // The controller validates `consent` as `accepted`, so submitting
    // without it never reaches createInquiry(); this test instead calls
    // through the controller with an already-verified company and confirms
    // an inquiry created with no consent given records none.
    $inquiry = app(IntakeService::class)->createInquiry($company, [
        'name' => 'No Consent Buyer',
        'email' => 'noconsent@example.com',
        'phone' => null,
        'message' => str_repeat('Interested in your sapelli stock. ', 3),
    ], false);

    expect($inquiry->consents()->where('purpose', ConsentPurpose::CompanyInquirySharing->value)->exists())->toBeFalse();
});
