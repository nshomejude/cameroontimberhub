<?php

namespace App\Enums;

/** Mirrors the `conversations_status_check` CHECK constraint exactly. */
enum ConversationStatus: string
{
    case Open = 'open';
    case Archived = 'archived';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Archived => 'Archived',
            self::Closed => 'Closed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
