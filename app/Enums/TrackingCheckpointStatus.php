<?php

namespace App\Enums;

/**
 * A closed set of manual shipment-tracking checkpoint statuses (gap-plan
 * 1.5.11: "Manual checkpoint tracking ... No telematics yet"). Kept as an
 * application-level enum rather than a DB CHECK constraint enum so a new
 * status can be added without a migration, following the same reasoning as
 * this table's other "no device feed" columns.
 *
 * Named distinctly from App\Enums\CheckpointStatus, which is an unrelated,
 * pre-existing enum for Verification review-checkpoint outcomes
 * (Pending/Approved/Rejected/NeedsMoreInfo) — see app/Models/VerificationCheckpoint.php.
 */
enum TrackingCheckpointStatus: string
{
    case Dispatched = 'dispatched';
    case InTransit = 'in_transit';
    case Delayed = 'delayed';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Dispatched => 'Dispatched',
            self::InTransit => 'In transit',
            self::Delayed => 'Delayed',
            self::Delivered => 'Delivered',
        };
    }
}
