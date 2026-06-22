<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending review',
            self::Verified => 'Verified',
            self::Suspended => 'Suspended',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Suspended => 'danger',
            self::Rejected => 'danger',
            self::Archived => 'gray',
        };
    }
}
