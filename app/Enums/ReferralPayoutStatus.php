<?php

namespace App\Enums;

/**
 * Lifecycle of one referral payout attempt (App\Models\ReferralPayout).
 *
 * requested → (second admin) → processing → succeeded | unclaimed | failed
 * requested → rejected
 * unclaimed → succeeded (receiver claimed) | failed (returned after 30 days)
 *
 * `requested`, `processing`, `unclaimed` and `succeeded` are "live": the DB
 * allows only one live attempt per earning. `failed` / `rejected` leave the
 * earning payable again.
 */
enum ReferralPayoutStatus: string
{
    case Requested = 'requested';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Unclaimed = 'unclaimed';
    case Failed = 'failed';

    /** @return list<self> */
    public static function live(): array
    {
        return [self::Requested, self::Processing, self::Unclaimed, self::Succeeded];
    }

    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting second approval',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::Succeeded => 'Paid',
            self::Unclaimed => 'Unclaimed',
            self::Failed => 'Failed',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Rejected => 'gray',
            self::Processing => 'info',
            self::Succeeded => 'success',
            self::Unclaimed => 'warning',
            self::Failed => 'danger',
        };
    }
}
