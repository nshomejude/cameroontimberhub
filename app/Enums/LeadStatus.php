<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Won = 'won';
    case Lost = 'lost';
    case Dormant = 'dormant';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Won => 'Won',
            self::Lost => 'Lost',
            self::Dormant => 'Dormant',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Contacted => 'info',
            self::Won => 'success',
            self::Lost => 'danger',
            self::Dormant => 'gray',
        };
    }
}
