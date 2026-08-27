<?php

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\Rfq;
use Illuminate\Support\Str;

function makeConsentRfq(array $attributes = []): Rfq
{
    return Rfq::create(array_merge([
        'reference_code' => 'RFQ-2026-'.Str::upper(Str::random(5)),
        'buyer_name' => 'Buyer',
        'buyer_email' => 'buyer@acme.test',
        'status' => 'new',
        'visibility' => 'public',
    ], $attributes));
}

it('grants a polymorphic consent to a subject with structured scope and evidence', function () {
    $rfq = makeConsentRfq();

    $consent = Consent::create([
        'subject_type' => Rfq::class,
        'subject_id' => $rfq->id,
        'purpose' => ConsentPurpose::RfqExporterSharing,
        'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
        'granted_at' => now(),
        'evidence' => ['ip_address' => '203.0.113.9', 'user_agent' => 'PestTestAgent/1.0', 'captured_at' => now()->toIso8601String()],
    ]);

    expect($consent->subject)->toBeInstanceOf(Rfq::class)
        ->and($consent->subject->is($rfq))->toBeTrue()
        ->and($consent->purpose)->toBe(ConsentPurpose::RfqExporterSharing)
        ->and($consent->scope)->toBe(['shared_with' => 'verified_exporters', 'contact_channel' => 'email'])
        ->and($consent->evidence['ip_address'])->toBe('203.0.113.9')
        ->and($consent->granted_at)->not->toBeNull()
        ->and($consent->revoked_at)->toBeNull()
        ->and($consent->isActive())->toBeTrue();
});

it('marks a consent revoked and no longer active', function () {
    $consent = Consent::factory()->for(makeConsentRfq(), 'subject')->create();

    expect($consent->isActive())->toBeTrue();

    $consent->revoke();

    expect($consent->fresh()->revoked_at)->not->toBeNull()
        ->and($consent->fresh()->isActive())->toBeFalse();
});

it('scopes to active (non-revoked) consents only', function () {
    $active = Consent::factory()->for(makeConsentRfq(), 'subject')->create();
    $revoked = Consent::factory()->for(makeConsentRfq(), 'subject')->create(['revoked_at' => now()]);

    $found = Consent::query()->active()->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->is($active))->toBeTrue()
        ->and($found->pluck('id'))->not->toContain($revoked->id);
});

it('scopes to a given subject', function () {
    $rfqA = makeConsentRfq();
    $rfqB = makeConsentRfq();
    Consent::factory()->for($rfqA, 'subject')->create();
    Consent::factory()->for($rfqB, 'subject')->create();

    $found = Consent::query()->forSubject($rfqA)->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->subject_id)->toBe($rfqA->id);
});

it('gives a subject a consents relation and an active-consent check', function () {
    $rfq = makeConsentRfq();
    Consent::factory()->for($rfq, 'subject')->create();
    $revokedRfq = makeConsentRfq();
    Consent::factory()->for($revokedRfq, 'subject')->create(['revoked_at' => now()]);

    expect($rfq->consents)->toHaveCount(1)
        ->and($rfq->hasActiveConsent(ConsentPurpose::RfqExporterSharing))->toBeTrue()
        ->and($revokedRfq->hasActiveConsent(ConsentPurpose::RfqExporterSharing))->toBeFalse();
});
