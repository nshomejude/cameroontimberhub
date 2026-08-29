<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The running-total quantity ledger against a certificate's
 * certified_quantity (docs/CERTIFICATE_SPEC.md Ring 2, Layer 14) --
 * prevents the same certificate being over-claimed across multiple
 * shipments/orders.
 */
class CertificateAllocationService
{
    public function allocate(Certificate $certificate, Model $consumer, float $quantity): CertificateAllocation
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Allocation quantity must be greater than zero.');
        }

        if ($certificate->certified_quantity === null) {
            throw new RuntimeException("Certificate [{$certificate->certificate_number}] has no certified quantity to allocate against.");
        }

        return DB::transaction(function () use ($certificate, $consumer, $quantity) {
            // lockForUpdate makes the "read remaining, then insert" check
            // atomic under concurrent allocation attempts against the same
            // certificate: a second transaction blocks on this row until the
            // first has committed its allocation, so both cannot read the
            // same remaining balance.
            $locked = Certificate::query()->whereKey($certificate->getKey())->lockForUpdate()->firstOrFail();
            $remaining = $this->remaining($locked);

            if ($quantity > $remaining) {
                throw new RuntimeException("Allocation of {$quantity} {$locked->quantity_unit} exceeds remaining balance of {$remaining} {$locked->quantity_unit} on certificate [{$locked->certificate_number}].");
            }

            return CertificateAllocation::create([
                'certificate_id' => $locked->id,
                'consumer_type' => $consumer::class,
                'consumer_id' => $consumer->getKey(),
                'quantity' => $quantity,
            ]);
        });
    }

    public function remaining(Certificate $certificate): float
    {
        // Sums allocations across every version sharing this
        // certificate_number, not just this row's own certificate_id --
        // a version is the same underlying commercial claim
        // (docs/GAP_PLAN.md item 0.8c: inherits, does not start clean).
        $allocated = (float) CertificateAllocation::query()
            ->whereIn('certificate_id', Certificate::query()->where('certificate_number', $certificate->certificate_number)->pluck('id'))
            ->sum('quantity');

        return (float) $certificate->certified_quantity - $allocated;
    }
}
