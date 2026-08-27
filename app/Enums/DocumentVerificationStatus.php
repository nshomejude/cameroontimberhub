<?php

namespace App\Enums;

/**
 * Where a Document stands in review, independent of whether it has expired
 * (see Document::isExpired()) — a document can be Verified and still expired,
 * which is exactly the state that should trigger a renewal reminder rather
 * than silently keep reading as trustworthy.
 */
enum DocumentVerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case NeedsCorrection = 'needs_correction';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::NeedsCorrection => 'Needs correction',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unverified => 'gray',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::NeedsCorrection => 'warning',
        };
    }
}
