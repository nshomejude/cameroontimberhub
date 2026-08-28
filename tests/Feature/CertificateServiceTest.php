<?php

use App\Enums\CertificateStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CertificateSigningService;

beforeEach(function () {
    $this->keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $this->keyPath, 'certificates.key_id' => 'test-key-1']);
    app(CertificateSigningService::class)->generateKeypair();
    $this->service = app(CertificateService::class);
});

afterEach(function () {
    @unlink($this->keyPath);
});

it('drafts a certificate for a subject with a hashed canonical payload', function () {
    $product = Product::factory()->create();

    $certificate = $this->service->draft($product, [
        'product' => 'Sapelli sawn timber',
        'origin' => ['country' => 'Cameroon'],
    ], quantity: 100.0, unit: 'm3');

    expect($certificate->status)->toBe(CertificateStatus::Draft)
        ->and($certificate->data_hash)->not->toBeNull()
        ->and($certificate->data_hash)->toHaveLength(64)
        ->and($certificate->version)->toBe(1);
});

it('signs a certificate, stamping key_id/algorithm/signature/signed_at', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $officer = User::factory()->create();

    $signed = $this->service->sign($certificate, $officer);

    expect($signed->key_id)->toBe('test-key-1')
        ->and($signed->algorithm)->toBe('ed25519')
        ->and($signed->signature)->not->toBeNull()
        ->and($signed->signed_at)->not->toBeNull()
        ->and($signed->status)->toBe(CertificateStatus::Verified);
});

it('produces a signature that verifies against the stored data hash', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $signed = $this->service->sign($certificate, User::factory()->create());

    expect(app(CertificateSigningService::class)->verify($signed->data_hash, $signed->signature))->toBeTrue();
});

it('issues a signed certificate, stamping issued_at and moving to active', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $officer = User::factory()->create();
    $signed = $this->service->sign($certificate, $officer);

    $issued = $this->service->issue($signed, $officer);

    expect($issued->status)->toBe(CertificateStatus::Active)
        ->and($issued->issued_at)->not->toBeNull();
});

it('refuses to issue a certificate that has not been signed', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');

    expect(fn () => $this->service->issue($certificate, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('refuses to create a version without a reason', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');

    expect(fn () => $this->service->createVersion($certificate, ['product' => 'x'], '   ', User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('creates a new version with its own hash and signature, and marks the prior version superseded, sharing the same certificate_number', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $officer = User::factory()->create();
    $issued = $this->service->issue($this->service->sign($certificate, $officer), $officer);

    $newVersion = $this->service->createVersion(
        $issued,
        ['product' => 'Sapelli sawn timber, corrected grade'],
        'Grade correction after re-inspection',
        $officer,
    );

    expect($newVersion->certificate_number)->toBe($issued->certificate_number)
        ->and($newVersion->version)->toBe(2)
        ->and($newVersion->previous_version_id)->toBe($issued->id)
        ->and($newVersion->version_reason)->toBe('Grade correction after re-inspection')
        ->and($newVersion->version_actor_id)->toBe($officer->id)
        ->and($newVersion->data_hash)->not->toBe($issued->data_hash)
        ->and($newVersion->verification_token)->not->toBe($issued->verification_token)
        ->and($issued->fresh()->status)->toBe(CertificateStatus::Superseded);
});
