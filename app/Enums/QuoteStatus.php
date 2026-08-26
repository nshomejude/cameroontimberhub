<?php

namespace App\Enums;

/**
 * Lifecycle of a supplier quote against a routed RFQ.
 *
 * Mirrors the `quotes_status_check` CHECK constraint exactly.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Viewed => 'Viewed',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::Viewed => 'warning',
            self::Accepted => 'success',
            self::Declined, self::Withdrawn => 'gray',
            self::Expired => 'danger',
        };
    }

    /** Statuses a buyer is allowed to see. Drafts never leave the supplier. */
    public static function buyerVisible(): array
    {
        return [
            self::Submitted->value,
            self::Viewed->value,
            self::Accepted->value,
            self::Declined->value,
            self::Expired->value,
        ];
    }

    /** True while the quote is still a live offer the buyer can act on. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::Viewed], true);
    }

    /** True once the quote can no longer change. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Declined, self::Withdrawn, self::Expired], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
