<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('creates a certificate with a public certificate number distinct from its db id, and a separate verification token', function () {
    $product = Product::factory()->create();

    $certificate = Certificate::create([
        'certificate_number' => 'TH-CMR-ORG-2026-'.strtoupper(Str::random(10)),
        'verification_token' => Str::random(48),
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'data' => ['product' => 'Sapelli sawn timber', 'origin' => ['country' => 'Cameroon']],
        'version' => 1,
        'certified_quantity' => 120.5,
        'quantity_unit' => 'm3',
        'status' => CertificateStatus::Draft,
    ]);

    expect($certificate->certificate_number)->not->toBe((string) $certificate->id)
        ->and($certificate->verification_token)->not->toBe($certificate->certificate_number)
        ->and($certificate->subject)->toBeInstanceOf(Product::class)
        ->and($certificate->subject->is($product))->toBeTrue()
        ->and($certificate->status)->toBe(CertificateStatus::Draft)
        ->and($certificate->version)->toBe(1);
});

it('rejects an invalid status value at the database level via the CHECK constraint', function () {
    $product = Product::factory()->create();

    expect(fn () => DB::table('certificates')->insert([
        'certificate_number' => 'TH-CMR-ORG-2026-BADSTATUS01',
        'verification_token' => Str::random(48),
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'data' => '{}',
        'version' => 1,
        'status' => 'not_a_real_status',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('never derives the verification token from the certificate number', function () {
    $certificate = Certificate::factory()->create([
        'certificate_number' => 'TH-CMR-ORG-2026-FIXEDVALUE01',
    ]);

    expect($certificate->verification_token)->not->toBe(md5($certificate->certificate_number))
        ->and($certificate->verification_token)->not->toBe(sha1($certificate->certificate_number))
        ->and(strlen($certificate->verification_token))->toBeGreaterThanOrEqual(32);
});

it('allows two versions to share one certificate_number but not one number+version pair', function () {
    $first = Certificate::factory()->create(['status' => CertificateStatus::Superseded]);

    $second = Certificate::factory()->create([
        'certificate_number' => $first->certificate_number,
        'version' => 2,
        'previous_version_id' => $first->id,
    ]);

    expect($second->certificate_number)->toBe($first->certificate_number);

    expect(fn () => Certificate::factory()->create([
        'certificate_number' => $first->certificate_number,
        'version' => 2,
    ]))->toThrow(QueryException::class);
});

it('permits only one live version per certificate_number at the database level', function () {
    $live = Certificate::factory()->create(['status' => CertificateStatus::Active]);

    expect(fn () => Certificate::factory()->create([
        'certificate_number' => $live->certificate_number,
        'version' => 2,
        'status' => CertificateStatus::Draft,
    ]))->toThrow(QueryException::class);
});
