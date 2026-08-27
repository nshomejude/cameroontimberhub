<?php

namespace App\Enums;

/**
 * Sequential stage of a polymorphic Verification (spec §3.4, gap-plan 0.2).
 * These are the brief's 8 named stages plus the `Rejected` terminal branch
 * off `ComplianceReview`. `needs_more_info` is deliberately NOT a case here —
 * it is a per-checkpoint outcome (see CheckpointStatus::NeedsMoreInfo) that
 * leaves the parent Verification's stage unchanged; see
 * Verification::needsMoreInfo().
 */
enum VerificationStage: string
{
    case Registered = 'registered';
    case CompanyInfo = 'company_info';
    case BusinessDocs = 'business_docs';
    case IdentityKyc = 'identity_kyc';
    case ForestryLegalDocs = 'forestry_legal_docs';
    case ComplianceReview = 'compliance_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::CompanyInfo => 'Company info',
            self::BusinessDocs => 'Business documents',
            self::IdentityKyc => 'Identity / KYC',
            self::ForestryLegalDocs => 'Forestry legal documents',
            self::ComplianceReview => 'Compliance review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Published => 'Published',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Registered, self::CompanyInfo, self::BusinessDocs, self::IdentityKyc, self::ForestryLegalDocs => 'gray',
            self::ComplianceReview => 'warning',
            self::Verified, self::Published => 'success',
            self::Rejected => 'danger',
        };
    }

    /** Terminal stages a Verification does not transition out of. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Published], true);
    }
}
