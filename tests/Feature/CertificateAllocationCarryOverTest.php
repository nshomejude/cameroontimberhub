<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Quote;
use App\Services\CertificateAllocationService;

beforeEach(function () {
    $this->service = app(CertificateAllocationService::class);
});

it('carries allocations forward across a version boundary, so a re-issued certificate cannot double-claim already-allocated quantity', function () {
    $v1 = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3', 'certificate_number' => 'TH-CARRY-TEST-01', 'version' => 1]);
    $this->service->allocate($v1, Quote::factory()->create(), 60);

    // Simulate createVersion(): a new row, same certificate_number, same
    // certified_quantity. v1 is marked superseded because the database
    // enforces at most one non-superseded row per certificate_number
    // (certificates_one_live_version_idx) -- the shared certificate_number
    // is what matters for the carry-over behaviour under test here.
    $v1->update(['status' => CertificateStatus::Superseded]);
    $v2 = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3', 'certificate_number' => 'TH-CARRY-TEST-01', 'version' => 2, 'previous_version_id' => $v1->id]);

    expect($this->service->remaining($v2))->toEqual(40);

    // The remaining 40 is genuinely allocatable against the NEW version...
    $this->service->allocate($v2, Quote::factory()->create(), 40);
    expect($this->service->remaining($v2))->toEqual(0);

    // ...but no more, even though $v2's own certificate_allocations rows
    // only sum to 40 -- the v1 allocation of 60 must still count.
    expect(fn () => $this->service->allocate($v2, Quote::factory()->create(), 1))
        ->toThrow(RuntimeException::class, 'exceeds remaining');
});

it('still works correctly for a certificate with only one version (no regression)', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50, 'quantity_unit' => 'm3']);
    $this->service->allocate($certificate, Quote::factory()->create(), 20);

    expect($this->service->remaining($certificate))->toEqual(30);
});
