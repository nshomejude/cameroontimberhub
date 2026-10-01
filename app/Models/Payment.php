<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Services\Payments\ProviderFeeCalculator;
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
            'base_amount' => 'decimal:2',
            'provider_fee_amount' => 'decimal:2',
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

    /**
     * What the platform is owed for the purchase: `amount` minus a provider
     * fee passed through to the payer (plan price incl. any tax). Rows from
     * before fee accounting have no base_amount and fall back to `amount`.
     * Use this — never `amount` — for revenue-share maths (e.g. referral
     * commission), so the payer's processing fee is never shared out.
     */
    public function baseAmount(): string
    {
        return bcadd((string) ($this->base_amount ?? $this->amount ?? '0'), '0', 2);
    }

    /**
     * The pre-tax, pre-fee price (the tax breakdown's subtotal when a tax
     * rule applied at checkout, else baseAmount()).
     */
    public function subtotalAmount(): string
    {
        $tax = is_array($this->metadata['tax'] ?? null) ? $this->metadata['tax'] : null;

        return $tax !== null && isset($tax['subtotal'])
            ? bcadd((string) $tax['subtotal'], '0', 2)
            : $this->baseAmount();
    }

    /** True when the payer was charged a provider fee on top of the price. */
    public function providerFeePassedThrough(): bool
    {
        return $this->provider_fee_bearer === ProviderFeeCalculator::BEARER_BUYER
            && (float) $this->provider_fee_amount > 0;
    }

    /**
     * Complete a pending attempt — or a failed one the provider later
     * confirms (e.g. a capture that timed out on our side but went through):
     * the money moved, so it must settle. Returns true only when THIS call
     * made the transition. Completed / refunded / cancelled payments are
     * left untouched, so a replayed webhook or return leg can neither
     * re-stamp `paid_at` nor resurrect a refunded payment. Conditional
     * UPDATE keeps it race-safe against a concurrent completion.
     */
    public function markCompleted(?string $providerReference = null): bool
    {
        $values = ['status' => PaymentStatus::Completed->value, 'paid_at' => now(), 'updated_at' => now()];

        if ($providerReference !== null) {
            $values['provider_reference'] = $providerReference;
        }

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->update($values);

        $this->refresh();

        return $updated > 0;
    }

    /**
     * Fail a still-pending attempt. A no-op for any other status so a stray
     * cancel link, late gateway callback or replayed request can never flip
     * a completed/refunded payment back to failed. Conditional UPDATE keeps
     * it race-safe against a concurrent completion.
     */
    public function markFailed(): void
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Failed->value, 'updated_at' => now()]);

        if ($updated > 0) {
            $this->status = PaymentStatus::Failed;
            $this->syncOriginalAttribute('status');
        }
    }

    /**
     * Pending check against the stored row (in-memory status can be null
     * right after create() since the DB default supplies it).
     */
    public function isPending(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->exists();
    }
}
