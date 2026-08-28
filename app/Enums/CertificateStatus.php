<?php

namespace App\Enums;

/**
 * Certificate lifecycle status (docs/CERTIFICATE_SPEC.md Ring 2, Layer 9).
 * Revoked/superseded/replaced/withdrawn certificates are never deleted --
 * the row stays, only status changes. Matches the spec's exact status list.
 */
enum CertificateStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Issued = 'issued';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Superseded = 'superseded';
    case Replaced = 'replaced';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Verified => 'Verified',
            self::Issued => 'Issued',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
            self::Superseded => 'Superseded',
            self::Replaced => 'Replaced',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Submitted, self::UnderReview => 'gray',
            self::Verified, self::Issued, self::Active => 'success',
            self::Suspended => 'warning',
            self::Revoked, self::Withdrawn => 'danger',
            self::Superseded, self::Replaced => 'gray',
        };
    }

    /** Statuses a verifier should read as "currently trustworthy". */
    public function isCurrentlyValid(): bool
    {
        return in_array($this, [self::Issued, self::Active], true);
    }

    /** Statuses that stop a certificate from being verified as good, without deleting it. */
    public function isTerminalNegative(): bool
    {
        return in_array($this, [self::Revoked, self::Superseded, self::Replaced, self::Withdrawn], true);
    }
}
