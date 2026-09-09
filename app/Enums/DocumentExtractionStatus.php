<?php

namespace App\Enums;

enum DocumentExtractionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::NeedsReview => 'Needs review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::NeedsReview => 'warning',
        };
    }
}
