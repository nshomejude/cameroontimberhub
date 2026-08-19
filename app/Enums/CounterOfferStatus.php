<?php

namespace App\Enums;

/**
 * Lifecycle of one negotiation round.
 *
 * Mirrors the `quote_counter_offers_status_check` CHECK constraint exactly.
 */
enum CounterOfferStatus: string
{
    /** Awaiting the counterparty's answer. At most one per quote. */
    case Pending = 'pending';

    /** The counterparty agreed. Produced a revised quote. */
    case Accepted = 'accepted';

    /** The counterparty said no and did not propose anything else. */
    case Declined = 'declined';

    /** The counterparty answered with a counter of their own. */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting reply',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Superseded => 'Countered',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Declined => 'danger',
            self::Superseded => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
