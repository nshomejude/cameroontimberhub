<?php

namespace App\Models;

use App\Enums\ReferralPayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay a ReferralEarning — via PayPal Payouts (two-person
 * approved) or recorded manually (MoMo / bank). Written only by
 * App\Services\Referrals\ReferralPayoutService.
 */
class ReferralPayout extends Model
{
    public const METHOD_PAYPAL = 'paypal';

    public const METHOD_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReferralPayoutStatus::class,
            'amount' => 'decimal:2',
            'receiver_email' => 'encrypted',
            'provider_payload' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(ReferralEarning::class, 'referral_earning_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPaypal(): bool
    {
        return $this->method === self::METHOD_PAYPAL;
    }

    public function maskedReceiver(): ?string
    {
        return ReferralPayoutProfile::mask($this->receiver_email);
    }
}
