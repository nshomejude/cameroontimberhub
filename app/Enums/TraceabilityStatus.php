<?php

namespace App\Enums;

enum TraceabilityStatus: string
{
    case NotTraceable = 'not_traceable';
    case Partial = 'partial';
    case Traceable = 'traceable';
    case FullyTraceable = 'fully_traceable';

    public function label(): string
    {
        return match ($this) {
            self::NotTraceable => 'Not traceable',
            self::Partial => 'Partially traceable',
            self::Traceable => 'Traceable',
            self::FullyTraceable => 'Fully traceable',
        };
    }
}
