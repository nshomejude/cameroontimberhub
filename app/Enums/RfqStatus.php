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
        return __('messages.enums.rfq_status.'.$this->value);
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
