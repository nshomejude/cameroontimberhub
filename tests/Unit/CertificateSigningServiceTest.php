<?php

use App\Services\CertificateSigningService;

beforeEach(function () {
    $this->keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $this->keyPath, 'certificates.key_id' => 'test-key-1']);
});

afterEach(function () {
    @unlink($this->keyPath);
});

it('generates a real Ed25519 keypair and writes only the key material to the configured path', function () {
    $service = new CertificateSigningService;
    $service->generateKeypair();

    expect(file_exists($this->keyPath))->toBeTrue();

    $stored = json_decode(file_get_contents($this->keyPath), true);
    expect($stored)->toHaveKeys(['key_id', 'private_key', 'public_key'])
        ->and($stored['key_id'])->toBe('test-key-1');
});

it('signs a hash and the signature verifies against the matching public key', function () {
    $service = new CertificateSigningService;
    $service->generateKeypair();

    $hash = hash('sha256', 'canonical-payload');
    $result = $service->sign($hash);

    expect($result)->toHaveKeys(['key_id', 'algorithm', 'signature'])
        ->and($result['algorithm'])->toBe('ed25519')
        ->and($service->verify($hash, $result['signature']))->toBeTrue();
});

it('fails verification if the hash was tampered with after signing', function () {
    $service = new CertificateSigningService;
    $service->generateKeypair();

    $result = $service->sign(hash('sha256', 'original-payload'));

    expect($service->verify(hash('sha256', 'tampered-payload'), $result['signature']))->toBeFalse();
});

it('returns false rather than throwing for a malformed signature', function () {
    $service = new CertificateSigningService;
    $service->generateKeypair();

    expect($service->verify(hash('sha256', 'x'), base64_encode('too-short')))->toBeFalse()
        ->and($service->verify(hash('sha256', 'x'), 'not even base64 !!!'))->toBeFalse();
});

it('throws a clear error if asked to sign before a keypair exists', function () {
    $service = new CertificateSigningService;

    expect(fn () => $service->sign(hash('sha256', 'x')))
        ->toThrow(RuntimeException::class, 'signing key not found');
});
