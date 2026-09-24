<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function certificateStaff(): User
{
    $staff = User::factory()->create();
    $staff->givePermissionTo('certificates.manage');

    return $staff;
}

it('renders the certificate print view with QR, fingerprint, and the mandatory legal disclaimer', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'x'),
        'signature' => base64_encode('sig'),
        'key_id' => 'test-key-1',
        'algorithm' => 'ed25519',
        'geospatial_data' => ['type' => 'Point', 'coordinates' => ['11.518890', '3.848210']],
        'geospatial_hash' => hash('sha256', 'g'),
        'data' => ['product' => 'Sapelli sawn timber', 'origin' => ['country' => 'Cameroon']],
    ]);

    $response = $this->actingAs(certificateStaff())->get(route('certificates.show', $certificate->certificate_number));

    $response->assertOk()
        ->assertSee($certificate->certificate_number)
        ->assertSee($certificate->fingerprint())
        ->assertSee('digitally verifiable record', escape: false)
        ->assertSee('11.518890')
        ->assertSee('data:image/svg+xml', escape: false)
        ->assertSee('window.print()', escape: false);
});

it('shows the geospatial panel as text-only lat/long, honestly, with no fabricated map image', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'geospatial_data' => ['type' => 'Point', 'coordinates' => ['11.518890', '3.848210']],
        'geospatial_hash' => hash('sha256', 'g'),
    ]);

    $response = $this->actingAs(certificateStaff())->get(route('certificates.show', $certificate->certificate_number));

    // The only images on the page are the brand logo and the verification QR.
    // Nothing renders a map tile or a static-map URL, because no maps
    // provider is configured in this codebase.
    $response->assertOk()
        ->assertSee('Latitude')
        ->assertDontSee('staticmap', escape: false)
        ->assertDontSee('maps.googleapis', escape: false)
        ->assertDontSee('openstreetmap', escape: false);
});

it('renders the six decimal places EUDR requires, rather than truncating them', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'geospatial_data' => ['type' => 'Point', 'coordinates' => ['11.520000', '3.850000']],
    ]);

    $this->actingAs(certificateStaff())->get(route('certificates.show', $certificate->certificate_number))
        ->assertSee('11.520000')
        ->assertSee('3.850000');
});

it('serves the current live version when several versions share a number', function () {
    $old = Certificate::factory()->create(['status' => CertificateStatus::Superseded, 'version' => 1]);
    $current = Certificate::factory()->create([
        'certificate_number' => $old->certificate_number,
        'version' => 2,
        'status' => CertificateStatus::Active,
    ]);

    $this->actingAs(certificateStaff())->get(route('certificates.show', $old->certificate_number))
        ->assertOk()
        ->assertSee('Version 2');

    expect($current->version)->toBe(2);
});

it('renders a Code 128 barcode of the certificate number alongside the QR code', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'barcode-fixture'),
    ]);

    $response = $this->actingAs(certificateStaff())->get(route('certificates.show', $certificate->certificate_number));

    $response->assertOk()
        ->assertSee('Barcode: '.$certificate->certificate_number, escape: false);

    // Two distinct inline SVG data URIs: the QR code and the barcode.
    expect(substr_count($response->getContent(), 'data:image/svg+xml'))->toBeGreaterThanOrEqual(2);
});

it('renders the deterministic watermark background but no VOID stamp for a live certificate', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'watermark-fixture'),
    ]);

    $response = $this->actingAs(certificateStaff())->get(route('certificates.show', $certificate->certificate_number));

    $response->assertOk()
        ->assertSee('certificate-watermark', escape: false)
        ->assertDontSee('certificate-void-stamp', escape: false);
});

it('flags a superseded certificate for the VOID stamp at the service level', function () {
    // certificates.show only ever serves ::liveVersion() (see
    // Certificate::scopeLiveVersion(), which excludes Superseded/Replaced),
    // so a bare Superseded certificate 404s at the HTTP layer -- superseding
    // always means a newer live version exists. The stamp logic itself is
    // still exercised directly here, exactly as it would be for any future
    // view that does render a non-live version.
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Superseded,
        'data_hash' => hash('sha256', 'void-fixture'),
    ]);

    $watermark = new App\Services\CertificateWatermarkService;

    expect($watermark->shouldShowVoidStamp($certificate))->toBeTrue()
        ->and($watermark->voidLabel($certificate))->toBe('VOID');
});

it('stamps a REVOKED watermark on a revoked certificate', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Revoked,
        'data_hash' => hash('sha256', 'revoked-fixture'),
    ]);

    $this->actingAs(certificateStaff())->get(route('certificates.show', $certificate->certificate_number))
        ->assertOk()
        ->assertSee('certificate-void-stamp', escape: false)
        ->assertSee('REVOKED');
});

it('renders the same watermark pattern for the same data_hash and a different one for a different data_hash', function () {
    // Unpersisted in-memory models: the point of this assertion is purely
    // that CertificateWatermarkService::backgroundStyle() is a pure
    // function of certificate_number + data_hash, so there is no need to
    // fight the DB's one-live-version-per-number constraint to prove it.
    $number = 'TH-CMR-ORG-2026-FIXEDNUM01';
    $certificateA = Certificate::factory()->make(['certificate_number' => $number, 'data_hash' => hash('sha256', 'same')]);
    $certificateB = Certificate::factory()->make(['certificate_number' => $number, 'data_hash' => hash('sha256', 'same')]);
    $certificateC = Certificate::factory()->make(['certificate_number' => $number, 'data_hash' => hash('sha256', 'different')]);

    $watermark = new App\Services\CertificateWatermarkService;

    expect($watermark->backgroundStyle($certificateA))
        ->toBe($watermark->backgroundStyle($certificateB))
        ->not->toBe($watermark->backgroundStyle($certificateC));
});

it('refuses a signed-in user without the certificates.manage permission', function () {
    $certificate = Certificate::factory()->create(['status' => CertificateStatus::Active]);

    $this->actingAs(User::factory()->create())
        ->get(route('certificates.show', $certificate->certificate_number))
        ->assertForbidden();
});

it('redirects a guest to login rather than rendering the certificate', function () {
    $certificate = Certificate::factory()->create(['status' => CertificateStatus::Active]);

    $this->get(route('certificates.show', $certificate->certificate_number))
        ->assertRedirect(route('login'));
});
