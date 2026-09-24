<?php

namespace App\Enums;

enum ReferralEarningStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }
}
