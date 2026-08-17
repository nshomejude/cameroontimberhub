<?php

use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\User;
use App\Services\InquiryTriageService;
use Illuminate\Support\Facades\Config;
use Spatie\Activitylog\Models\Activity;

function makeInquiry(array $attributes = []): CompanyInquiry
{
    return CompanyInquiry::create(array_merge([
        'company_id' => Company::factory()->create()->id,
        'name' => 'Bob Buyer',
        'email' => 'bob@acme.test',
        'message' => 'Interested in your sawn timber for export to Europe.',
        'status' => 'new',
    ], $attributes));
}

it('allows the documented legal transitions', function () {
    $inquiry = makeInquiry();
    $admin = User::factory()->create();
    $service = app(InquiryTriageService::class);

    $service->startReview($inquiry, $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::InReview);

    $service->approve($inquiry->fresh(), $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Approved);

    $service->close($inquiry->fresh(), $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Closed);
});

it('rejects illegal transitions', function () {
    $inquiry = makeInquiry(['status' => 'closed']);
    $admin = User::factory()->create();
    $service = app(InquiryTriageService::class);

    $service->approve($inquiry, $admin);
})->throws(RuntimeException::class, 'Illegal inquiry transition closed -> approved');

it('marks spam and logs the reason on reject', function () {
    $inquiry = makeInquiry();
    $admin = User::factory()->create();
    $service = app(InquiryTriageService::class);

    $service->markSpam($inquiry, $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Spam);

    $service->reject($inquiry->fresh(), 'Duplicate submission', $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Rejected);

    $activity = Activity::where('log_name', 'inquiry')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties['to'])->toBe('rejected')
        ->and($activity->properties['reason'])->toBe('Duplicate submission');
});
