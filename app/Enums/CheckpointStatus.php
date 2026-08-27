<?php

namespace App\Enums;

/**
 * Outcome of a single review request against a Verification at a given
 * stage (spec §3.4's "needs_more_info at request level" — gap-plan 0.2).
 * A checkpoint's status is independent of Verification::stage: a
 * NeedsMoreInfo checkpoint leaves the parent stage where it was, it does not
 * push the Verification into some "needs_more_info" state.
 */
enum CheckpointStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsMoreInfo = 'needs_more_info';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NeedsMoreInfo => 'Needs more info',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::NeedsMoreInfo => 'warning',
        };
    }
}
