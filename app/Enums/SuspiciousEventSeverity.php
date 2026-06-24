<?php

namespace App\Enums;

enum SuspiciousEventSeverity: string
{
    case Low    = 'low';
    case Medium = 'medium';
    case High   = 'high';

    public function color(): string
    {
        return match ($this) {
            self::Low    => 'gray',
            self::Medium => 'warning',
            self::High   => 'danger',
        };
    }
}
