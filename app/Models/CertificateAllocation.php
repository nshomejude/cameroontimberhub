<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One claim against a certificate version's certified_quantity
 * (docs/CERTIFICATE_SPEC.md Ring 2, Layer 14). Always created through
 * CertificateAllocationService::allocate(), which is what enforces the
 * running-total balance atomically.
 */
class CertificateAllocation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function consumer(): MorphTo
    {
        return $this->morphTo();
    }
}
