<?php

namespace App\Enums;

enum RfqCompanyStatus: string
{
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Responded = 'responded';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::Responded => 'Responded',
            self::Declined => 'Declined',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'info',
            self::Viewed => 'warning',
            self::Responded => 'success',
            self::Declined => 'gray',
        };
    }
}
