<?php

namespace App\Enums;

enum FraudSignalStatus: string
{
    case Open = 'open';
    case Dismissed = 'dismissed';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Dismissed => 'Dismissed',
            self::Confirmed => 'Confirmed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Dismissed => 'gray',
            self::Confirmed => 'danger',
        };
    }
}
