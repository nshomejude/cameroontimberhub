<?php

namespace App\Enums;

/**
 * Trust-tier scale (blueprint §4 Trust Architecture). Layered ON TOP OF the
 * existing polymorphic Verification workflow (VerificationStage) and the
 * older VerificationBadge model -- neither is replaced. Tier 1 is derived
 * from VerificationStage reaching Verified/Published (see
 * Company::setVerificationTierAttribute()); tiers 2-5 have no automated
 * evidence source yet in this codebase and are administratively asserted by
 * staff holding `verification.review` until the underlying evidence systems
 * (operational document review, site visits, lot inspections) exist.
 *
 * The blueprint is explicit that the UI must always show the SCOPE of the
 * badge, not a bare "Verified" -- scopeDescription() is what a template
 * renders for that requirement.
 */
enum VerificationTier: int
{
    case Unverified = 0;
    case IdentityVerified = 1;
    case OperationallyVerified = 2;
    case TraceabilityVerified = 3;
    case FieldVerified = 4;
    case LotIndependentlyInspected = 5;

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::IdentityVerified => 'Identity Verified',
            self::OperationallyVerified => 'Operationally Verified',
            self::TraceabilityVerified => 'Traceability Verified',
            self::FieldVerified => 'Field/Site Verified',
            self::LotIndependentlyInspected => 'Lot Independently Inspected',
        };
    }

    /** The fuller, one-sentence explanation of what this tier actually attests. */
    public function scopeDescription(): string
    {
        return match ($this) {
            self::Unverified => 'The business is listed but has not passed CTH verification.',
            self::IdentityVerified => 'Evidence reviewed for legal identity, company registration, tax identity, and authorised representative.',
            self::OperationallyVerified => 'Level 1 plus relevant operational documentation.',
            self::TraceabilityVerified => 'Level 2 plus source/lot traceability evidence.',
            self::FieldVerified => 'Evidence supplemented with a physical/site verification.',
            self::LotIndependentlyInspected => 'A specific timber lot has passed an independent inspection.',
        };
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
