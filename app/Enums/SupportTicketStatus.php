<?php

namespace App\Enums;

/**
 * Support ticket lifecycle. `open` = awaiting staff, `pending` = awaiting the
 * user (staff replied), `resolved` = staff considers it answered (a user reply
 * reopens it), `closed` = terminal, no further user replies.
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Pending => 'Awaiting your reply',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Pending => 'info',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
