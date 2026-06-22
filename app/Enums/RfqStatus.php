<?php

namespace App\Enums;

enum RfqStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InReview => 'In review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Spam => 'Spam',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::InReview => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Spam => 'danger',
            self::Closed => 'gray',
        };
    }
}
