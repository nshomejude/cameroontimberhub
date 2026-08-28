<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Product;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CertificateSigningService;
use Illuminate\Support\Str;

it('finds an active certificate by its verification token and reports it valid', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'x'),
        'signature' => base64_encode('sig'),
        'key_id' => 'test-key-1',
        'algorithm' => 'ed25519',
        'evidence_manifest_hash' => hash('sha256', 'e'),
        'geospatial_hash' => hash('sha256', 'g'),
    ]);

    $response = $this->get(route('certificates.verify.token', $certificate->verification_token));

    $response->assertRedirect(route('certificates.verify'));
    $this->followRedirects($response)->assertSee($certificate->certificate_number);
});

it('reports a genuinely signed certificate as having a valid signature', function () {
    $keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $keyPath, 'certificates.key_id' => 'test-key-1']);
    app(CertificateSigningService::class)->generateKeypair();

    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $certificate = $service->issue(
        $service->sign($service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3'), $officer),
        $officer,
    );

    $page = $this->followRedirects($this->get(route('certificates.verify.token', $certificate->verification_token)));

    $page->assertSee('Valid');

    @unlink($keyPath);
});

it('reports a certificate whose signature does not verify as unverifiable, without erroring', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'x'),
        'signature' => base64_encode('not a real signature'),
    ]);

    $page = $this->followRedirects($this->get(route('certificates.verify.token', $certificate->verification_token)));

    $page->assertOk()->assertSee('Could not verify');
});

it('never discloses a distinguishing message for an unknown token vs a superseded one', function () {
    $superseded = Certificate::factory()->create(['status' => CertificateStatus::Superseded]);

    // The unknown token must satisfy the same route pattern as a real one,
    // or the comparison would be 404-vs-302 rather than a disclosure test.
    $unknownResponse = $this->get(route('certificates.verify.token', Str::random(48)));
    $supersededResponse = $this->get(route('certificates.verify.token', $superseded->verification_token));

    // Both redirect to the same generic result flow -- no distinguishing
    // status code or redirect target between "never existed" and "exists but
    // is not the current version".
    expect($unknownResponse->status())->toBe($supersededResponse->status())
        ->and($unknownResponse->headers->get('Location'))->toBe($supersededResponse->headers->get('Location'));
});

it('tells a holder that a superseded version is real but no longer current', function () {
    $original = Certificate::factory()->create(['status' => CertificateStatus::Superseded, 'version' => 1]);
    Certificate::factory()->create([
        'certificate_number' => $original->certificate_number,
        'version' => 2,
        'status' => CertificateStatus::Active,
    ]);

    $page = $this->followRedirects($this->get(route('certificates.verify.token', $original->verification_token)));

    $page->assertSee('no longer the current version');
});

it('does not leak the subject product name, only the allow-listed public payload fields', function () {
    $product = Product::factory()->create(['name' => 'Internal Product Name']);
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'data' => ['product' => 'Public certified product label'],
        'data_hash' => hash('sha256', 'x'),
    ]);

    $page = $this->followRedirects($this->get(route('certificates.verify.token', $certificate->verification_token)));

    $page->assertSee('Public certified product label')
        ->assertDontSee('Internal Product Name');
});

it('throttles repeated verification attempts', function () {
    $certificate = Certificate::factory()->create(['status' => CertificateStatus::Active]);

    foreach (range(1, 10) as $ignored) {
        $this->get(route('certificates.verify.token', $certificate->verification_token));
    }

    $this->get(route('certificates.verify.token', $certificate->verification_token))
        ->assertStatus(429);
});
