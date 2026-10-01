<?php

namespace App\Enums;

/**
 * Third-party carrier booking state on a Shipment (owner decision: direct
 * assignment AND booking acceptance coexist).
 *
 *  - Assigned: the supplier assigned the carrier directly — no action needed.
 *  - Pending:  the supplier requested a booking; the carrier must accept/decline.
 *  - Accepted: the carrier accepted a booking request.
 *  - Declined: the carrier declined; the carrier (and its fleet) was cleared.
 *
 * Null on the shipment means own fleet or no carrier yet.
 */
enum ShipmentCarrierStatus: string
{
    case Assigned = 'assigned';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::Pending => 'Awaiting carrier acceptance',
            self::Accepted => 'Accepted by carrier',
            self::Declined => 'Declined by carrier',
        };
    }

    /** Whether the carrier may operate the shipment (record checkpoints). */
    public function isActive(): bool
    {
        return in_array($this, [self::Assigned, self::Accepted], true);
    }
}
