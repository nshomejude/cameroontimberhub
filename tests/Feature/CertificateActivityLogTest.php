<?php

use App\Models\Certificate;
use App\Models\Product;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CertificateSigningService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $this->keyPath, 'certificates.key_id' => 'test-key-1']);
    app(CertificateSigningService::class)->generateKeypair();
});

afterEach(function () {
    @unlink($this->keyPath);
});

it('logs an activity entry when a certificate is signed, with the actor as causer', function () {
    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $certificate = $service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');

    $service->sign($certificate, $officer);

    $entry = Activity::query()->where('subject_type', Certificate::class)
        ->where('subject_id', $certificate->id)
        ->where('description', 'signed')
        ->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_type)->toBe(User::class)
        ->and($entry->causer_id)->toBe($officer->id);
});

it('logs a distinct activity entry for issuance and for version creation', function () {
    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $certificate = $service->sign($service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3'), $officer);
    $issued = $service->issue($certificate, $officer);

    $service->createVersion($issued, ['product' => 'Sapelli, corrected'], 'Correction', $officer);

    expect(Activity::query()->where('subject_id', $issued->id)->where('description', 'issued')->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'version_created')->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_id', $issued->id)->where('description', 'superseded')->exists())->toBeTrue();
});

it('records which certificate superseded the previous version', function () {
    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $issued = $service->issue($service->sign($service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3'), $officer), $officer);

    $next = $service->createVersion($issued, ['product' => 'corrected'], 'Correction', $officer);

    $entry = Activity::query()->where('subject_id', $issued->id)->where('description', 'superseded')->first();

    expect($entry->properties['superseded_by'])->toBe($next->id);
});

it('logs status changes as attribute diffs on the certificate itself', function () {
    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $certificate = $service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');

    $service->sign($certificate, $officer);

    $updated = Activity::query()->where('subject_type', Certificate::class)
        ->where('subject_id', $certificate->id)
        ->where('description', 'updated')
        ->latest('id')->first();

    expect($updated)->not->toBeNull()
        ->and($updated->properties['attributes']['status'])->toBe('verified')
        ->and($updated->properties['old']['status'])->toBe('draft');
});

it('administrators cannot delete audit entries through the model itself, only through the activity_log table directly', function () {
    // No delete-suppressing method exists on Activity for app code to call --
    // this test documents the invariant rather than exercising new code:
    // Certificate never exposes an action that deletes its own Activity rows.
    expect(method_exists(Certificate::class, 'clearActivity'))->toBeFalse();
});
