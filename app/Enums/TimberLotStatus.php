<?php

namespace App\Enums;

/** Blueprint §8's lot lifecycle states. */
enum TimberLotStatus: string
{
    case Draft = 'draft';
    case Available = 'available';
    case Reserved = 'reserved';
    case PartiallyAllocated = 'partially_allocated';
    case Sold = 'sold';
    case InProcessing = 'in_processing';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Released = 'released';
    case Quarantined = 'quarantined';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::PartiallyAllocated => 'Partially allocated',
            self::Sold => 'Sold',
            self::InProcessing => 'In processing',
            self::InTransit => 'In transit',
            self::Delivered => 'Delivered',
            self::Released => 'Released',
            self::Quarantined => 'Quarantined',
            self::Disputed => 'Disputed',
            self::Cancelled => 'Cancelled',
            self::Archived => 'Archived',
        };
    }
}
