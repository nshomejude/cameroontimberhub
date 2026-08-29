<?php

use App\Enums\ConsentPurpose;
use App\Models\ContactMessage;

it('has a ContactMessageSharing consent purpose accepted by the database CHECK constraint', function () {
    $message = ContactMessage::factory()->create();

    $consent = $message->consents()->create([
        'purpose' => ConsentPurpose::ContactMessageSharing,
        'granted_at' => now(),
    ]);

    expect($consent->fresh())->not->toBeNull();
});

it('gives ContactMessage the HasConsents trait', function () {
    $message = ContactMessage::factory()->create();

    expect($message->hasActiveConsent(ConsentPurpose::ContactMessageSharing))->toBeFalse();

    $message->consents()->create(['purpose' => ConsentPurpose::ContactMessageSharing, 'granted_at' => now()]);

    expect($message->fresh()->hasActiveConsent(ConsentPurpose::ContactMessageSharing))->toBeTrue();
});
