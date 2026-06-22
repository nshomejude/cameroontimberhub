<?php

namespace App\Enums;

enum VerificationRequestStatus: string
{
    case Pending = 'pending';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InReview => 'In review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::InReview => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
