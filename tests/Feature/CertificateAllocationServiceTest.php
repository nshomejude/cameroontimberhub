<?php

use App\Models\Certificate;
use App\Models\CertificateAllocation;
use App\Models\Quote;
use App\Services\CertificateAllocationService;
use Illuminate\Database\QueryException;

// The consumer is polymorphic. Quote stands in for an Order here because
// Order rows are only creatable through OrderService::createFromQuote() and
// therefore have no factory -- see App\Models\Order's docblock.

beforeEach(function () {
    $this->service = app(CertificateAllocationService::class);
});

it('allocates a portion of a certificate certified quantity against a consumer', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3']);
    $consumer = Quote::factory()->create();

    $allocation = $this->service->allocate($certificate, $consumer, 40);

    expect($allocation->quantity)->toEqual(40)
        ->and($allocation->consumer->is($consumer))->toBeTrue()
        ->and($this->service->remaining($certificate))->toEqual(60);
});

it('rejects an allocation that would exceed the remaining certified quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3']);
    $this->service->allocate($certificate, Quote::factory()->create(), 90);

    expect(fn () => $this->service->allocate($certificate, Quote::factory()->create(), 20))
        ->toThrow(RuntimeException::class, 'exceeds remaining');
});

it('allows multiple allocations that together exactly exhaust the certified quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50, 'quantity_unit' => 'm3']);

    $this->service->allocate($certificate, Quote::factory()->create(), 30);
    $this->service->allocate($certificate, Quote::factory()->create(), 20);

    expect($this->service->remaining($certificate))->toEqual(0);
});

it('rejects a non-positive allocation quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50, 'quantity_unit' => 'm3']);

    expect(fn () => $this->service->allocate($certificate, Quote::factory()->create(), 0))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->service->allocate($certificate, Quote::factory()->create(), -5))
        ->toThrow(RuntimeException::class);
});

it('refuses to allocate against a certificate with no certified quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => null]);

    expect(fn () => $this->service->allocate($certificate, Quote::factory()->create(), 5))
        ->toThrow(RuntimeException::class, 'no certified quantity');
});

it('rolls back rather than recording a rejected allocation', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 10, 'quantity_unit' => 'm3']);

    try {
        $this->service->allocate($certificate, Quote::factory()->create(), 25);
    } catch (RuntimeException) {
        // expected
    }

    expect(CertificateAllocation::where('certificate_id', $certificate->id)->count())->toBe(0)
        ->and($this->service->remaining($certificate))->toEqual(10);
});

it('refuses a non-positive quantity at the database level too', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50]);

    expect(fn () => CertificateAllocation::create([
        'certificate_id' => $certificate->id,
        'consumer_type' => Quote::class,
        'consumer_id' => Quote::factory()->create()->id,
        'quantity' => 0,
    ]))->toThrow(QueryException::class);
});
