<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single payment attempt against a company's Plan, through any of the 4
 * supported gateways (MTN Mobile Money, Orange Money, Stripe, PayPal — see
 * App\Enums\PaymentProvider). Each gateway's integration lives behind
 * App\Contracts\PaymentProviderContract and is responsible for creating and
 * updating its own Payment rows; this model itself is gateway-agnostic.
 */
class Payment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function markCompleted(?string $providerReference = null): void
    {
        $this->update([
            'status' => PaymentStatus::Completed,
            'provider_reference' => $providerReference ?? $this->provider_reference,
            'paid_at' => now(),
        ]);
    }

    public function markFailed(): void
    {
        $this->update(['status' => PaymentStatus::Failed]);
    }
}
