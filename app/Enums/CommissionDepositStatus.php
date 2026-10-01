<?php

namespace App\Enums;

/** A commission deposit awaiting / after finance review. Mirrors `commission_deposits_status_check`. */
enum CommissionDepositStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending verification',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Rejected => 'danger',
        };
    }
}
