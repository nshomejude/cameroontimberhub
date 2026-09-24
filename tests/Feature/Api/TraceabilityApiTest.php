<?php

use App\Enums\CertificateStatus;
use App\Enums\ProductStatus;
use App\Enums\TimberLotStatus;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\Product;
use App\Models\TimberLot;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CertificateSigningService;

it('returns a real timber passport for a public lot', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $lot = TimberLot::factory()->create([
        'company_id' => $company->getKey(),
        'status' => TimberLotStatus::Available,
    ]);

    $response = $this->getJson("/api/v1/lots/{$lot->lot_number}/passport")->assertOk();

    $response->assertJsonPath('data.lot_number', $lot->lot_number)
        ->assertJsonPath('data.status', TimberLotStatus::Available->value)
        ->assertJsonPath('data.volume_m3', (float) $lot->volume_m3)
        ->assertJsonPath('data.origin_region', $lot->origin_region)
        ->assertJsonPath('data.passport_url', route('passport.show', $lot));
});

it('404s a timber passport for a nonexistent lot number', function () {
    $this->getJson('/api/v1/lots/CTH-TIM-9999-999999/passport')->assertNotFound();
});

it('404s a timber passport for a draft lot or non-public company', function () {
    $hiddenCompany = Company::factory()->create();
    $draftLot = TimberLot::factory()->create([
        'company_id' => $hiddenCompany->getKey(),
        'status' => TimberLotStatus::Draft,
    ]);

    $this->getJson("/api/v1/lots/{$draftLot->lot_number}/passport")->assertNotFound();

    $visibleCompany = Company::factory()->publiclyVisible()->create();
    $draftLotVisibleCompany = TimberLot::factory()->create([
        'company_id' => $visibleCompany->getKey(),
        'status' => TimberLotStatus::Draft,
    ]);

    $this->getJson("/api/v1/lots/{$draftLotVisibleCompany->lot_number}/passport")->assertNotFound();
});

it('product traceability is null when no timber lots are linked', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->getKey(),
        'status' => ProductStatus::Active,
        // ProductObserver::maybeCreateLot() auto-creates a TimberLot for an
        // active product with a real moq_quantity — null it out so this
        // test genuinely has zero linked lots, not an accidental one.
        'moq_quantity' => null,
    ]);

    $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

    expect($response->json('data.traceability'))->toBeNull();
});

it('product traceability lists linked timber lots', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->getKey(),
        'status' => ProductStatus::Active,
        // See the null-traceability test above for why this is nulled out
        // — avoids ProductObserver auto-creating a second, unrelated lot.
        'moq_quantity' => null,
    ]);
    $lot = TimberLot::factory()->create([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'status' => TimberLotStatus::Available,
    ]);

    $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

    $lots = $response->json('data.traceability.lots');

    expect($lots)->toHaveCount(1)
        ->and($lots[0]['lot_number'])->toBe($lot->lot_number)
        ->and($lots[0]['passport_url'])->toBe(route('passport.show', $lot));

    $response->assertJsonPath('data.traceability.harvest_location.region', $lot->origin_region);
});

it('lists certificates filtered by company slug, excluding non-issued statuses', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $issued = signedCertificateFor($company, CertificateStatus::Active);

    Certificate::factory()->create([
        'subject_type' => Company::class,
        'subject_id' => $company->getKey(),
        'status' => CertificateStatus::Draft,
    ]);

    $otherCompany = Company::factory()->publiclyVisible()->create();
    signedCertificateFor($otherCompany, CertificateStatus::Active);

    $response = $this->getJson("/api/v1/certificates?company={$company->slug}")->assertOk();

    $numbers = collect($response->json('data'))->pluck('number');

    expect($numbers)->toEqual(collect([$issued->certificate_number]));
});

it('lists certificates filtered by product slug', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->getKey(),
        'status' => ProductStatus::Active,
    ]);

    $certificate = Certificate::factory()->create([
        'subject_type' => Product::class,
        'subject_id' => $product->getKey(),
        'status' => CertificateStatus::Issued,
    ]);

    $response = $this->getJson("/api/v1/certificates?product={$product->slug}")->assertOk();

    $numbers = collect($response->json('data'))->pluck('number');

    expect($numbers)->toEqual(collect([$certificate->certificate_number]));
});

it('404s a certificate detail lookup for a nonexistent certificate number', function () {
    $this->getJson('/api/v1/certificates/TH-CMR-ORG-9999-NOPE')->assertNotFound();
});

it('returns certificate detail with a valid verification_url and non-null hash/signature validity for a signed certificate', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $certificate = signedCertificateFor($company, CertificateStatus::Active);

    $response = $this->getJson("/api/v1/certificates/{$certificate->certificate_number}")->assertOk();

    $response->assertJsonPath('data.number', $certificate->certificate_number)
        ->assertJsonPath('data.status', CertificateStatus::Active->value)
        ->assertJsonPath('data.subject.type', 'Company')
        ->assertJsonPath('data.subject.slug', $company->slug)
        ->assertJsonPath('data.signature_valid', true);

    expect($response->json('data.verification_url'))
        ->toBe(route('certificates.verify.token', $certificate->fresh()->verification_token))
        ->and($response->json('data.data_hash'))->not->toBeNull();
});

/** Drafts, signs and issues a real Certificate for $company via the real CertificateService pipeline. */
function signedCertificateFor(Company $company, CertificateStatus $finalStatus): Certificate
{
    $keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $keyPath, 'certificates.key_id' => 'test-key-1']);
    app(CertificateSigningService::class)->generateKeypair();

    $service = app(CertificateService::class);
    $actor = User::factory()->create();

    $certificate = $service->draft($company, ['organisation' => $company->name], 100, 'm3');
    $certificate = $service->sign($certificate, $actor);

    if ($finalStatus === CertificateStatus::Active) {
        $certificate = $service->issue($certificate, $actor);
    }

    return $certificate->fresh();
}
