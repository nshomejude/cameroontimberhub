<?php

namespace App\Models;

use App\Enums\ReferralEarningStatus;
use App\Enums\ReferralPayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A referral commission owed to `referrer_user_id` for a referred company's
 * subscription payment. Lifecycle pending → approved → paid (admin-driven,
 * /admin → Referral earnings). Created only by ReferralService; paid only
 * through ReferralPayoutService (PayPal payout or manual record), which keeps
 * every attempt in `payouts`.
 */
class ReferralEarning extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReferralEarningStatus::class,
            'base_amount' => 'decimal:2',
            'rate_percent' => 'decimal:2',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function referredCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'referred_company_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(ReferralPayout::class);
    }

    public function latestPayout(): HasOne
    {
        return $this->hasOne(ReferralPayout::class)->latestOfMany();
    }

    /**
     * Referrer-facing payout state (web settings + API):
     * awaiting_approval | awaiting_payout | processing | unclaimed | failed | paid | cancelled.
     */
    public function payoutStatus(): string
    {
        if ($this->status === ReferralEarningStatus::Paid) {
            return 'paid';
        }

        if ($this->status === ReferralEarningStatus::Cancelled) {
            return 'cancelled';
        }

        return match ($this->latestPayout?->status) {
            ReferralPayoutStatus::Requested, ReferralPayoutStatus::Processing => 'processing',
            ReferralPayoutStatus::Unclaimed => 'unclaimed',
            ReferralPayoutStatus::Failed => 'failed',
            ReferralPayoutStatus::Succeeded => 'paid',
            default => $this->status === ReferralEarningStatus::Pending ? 'awaiting_approval' : 'awaiting_payout',
        };
    }

    public static function payoutStatusLabel(string $status): string
    {
        return match ($status) {
            'awaiting_approval' => 'Awaiting approval',
            'awaiting_payout' => 'Approved — awaiting payout',
            'processing' => 'Payout in progress',
            'unclaimed' => 'Unclaimed — sign in to PayPal with your payout email to claim',
            'failed' => 'Payout failed — check your payout email',
            'paid' => 'Paid',
            'cancelled' => 'Cancelled',
            default => ucfirst($status),
        };
    }

    public function amountLabel(): string
    {
        return self::money((float) $this->amount, (string) $this->currency);
    }

    public static function money(float $amount, string $currency): string
    {
        $decimals = strtoupper($currency) === 'XAF' ? 0 : 2;

        return strtoupper($currency).' '.number_format($amount, $decimals);
    }

    public function markApproved(?User $by = null): void
    {
        if ($this->status !== ReferralEarningStatus::Pending) {
            return;
        }

        $this->update(['status' => ReferralEarningStatus::Approved, 'approved_at' => now(), 'approved_by' => $by?->id]);
    }

    public function markPaid(?User $by = null): void
    {
        if (! in_array($this->status, [ReferralEarningStatus::Pending, ReferralEarningStatus::Approved], true)) {
            return;
        }

        $this->update([
            'status' => ReferralEarningStatus::Paid,
            'approved_at' => $this->approved_at ?? now(),
            'approved_by' => $this->approved_by ?? $by?->id,
            'paid_at' => now(),
            'paid_by' => $by?->id,
        ]);
    }

    /** Undo a payout that PayPal reversed / returned after success. */
    public function revertToApproved(): void
    {
        if ($this->status !== ReferralEarningStatus::Paid) {
            return;
        }

        $this->update(['status' => ReferralEarningStatus::Approved, 'paid_at' => null, 'paid_by' => null]);
    }
}
