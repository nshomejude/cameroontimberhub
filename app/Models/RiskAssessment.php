<?php

namespace App\Models;

use App\Enums\ComplianceCaseStatus;
use App\Enums\VerificationTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blueprint §7 Supplier Risk Engine: a structured, 10-dimension internal
 * risk score. Admin/staff-facing ONLY — the blueprint is explicit that raw
 * risk formulas must never be exposed to public users, only explainable
 * status indicators derived from this.
 *
 * Rows are APPENDED, never overwritten (see ComputeRiskAssessmentsCommand),
 * so a company accumulates a history/trend rather than one row per company.
 *
 * Honesty note (blueprint's own "evidence before claims" principle): several
 * dimensions below have NO dedicated real signal anywhere in this codebase
 * yet (no harvest-permit tracking, no independently-verified delivery
 * ledger, no dispute-tracking system). Those are explicitly documented as
 * neutral defaults (50) in computeFor() rather than fabricated precision.
 */
class RiskAssessment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'computed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Neutral-placeholder default for a dimension with no real signal source yet. */
    private const NEUTRAL = 50;

    /**
     * Derive a fresh risk assessment for $company from real, already-existing
     * signals in this codebase. Does NOT persist — callers decide whether/when
     * to save (see ComputeRiskAssessmentsCommand).
     */
    public static function computeFor(Company $company): self
    {
        $identity = self::identityRisk($company);
        $documentation = self::documentationRisk($company);
        $traceability = self::traceabilityRisk($company);
        $dispute = self::disputeRisk($company);
        $compliance = self::complianceRisk($company);
        $reputation = self::reputationRisk($company);
        $delivery = self::deliveryRisk($company);

        // No dedicated signal source exists in this codebase for these three
        // dimensions (no harvest-permit tracking, no independently-verified
        // product-condition data, no independently-verified transaction/
        // payment ledger) — neutral default rather than fabricated precision.
        $forestryOrigin = self::NEUTRAL;
        $product = self::NEUTRAL;
        $transaction = self::NEUTRAL;

        $dimensions = [
            'identity_risk' => $identity,
            'documentation_risk' => $documentation,
            'forestry_origin_risk' => $forestryOrigin,
            'traceability_risk' => $traceability,
            'product_risk' => $product,
            'delivery_risk' => $delivery,
            'transaction_risk' => $transaction,
            'dispute_risk' => $dispute,
            'compliance_risk' => $compliance,
            'reputation_risk' => $reputation,
        ];

        $composite = (int) round(array_sum($dimensions) / count($dimensions));

        return new self(array_merge($dimensions, [
            'company_id' => $company->getKey(),
            'composite_score' => $composite,
            'risk_band' => self::bandFor($composite),
            'computed_at' => now(),
        ]));
    }

    /** Blueprint's exact 5 bands: 0-19 / 20-39 / 40-59 / 60-79 / 80-100. */
    public static function bandFor(int $composite): string
    {
        return match (true) {
            $composite >= 80 => 'critical',
            $composite >= 60 => 'high',
            $composite >= 40 => 'elevated',
            $composite >= 20 => 'moderate',
            default => 'low_concern',
        };
    }

    /**
     * REAL signal: the existing Verification workflow (HasVerification) plus
     * the trust-tier scale (VerificationTier) layered on it earlier today.
     * Not verified at all -> high risk. Verified -> low risk, scaled down
     * further by how deep the trust tier goes (tier is capped by
     * Company::setVerificationTierAttribute() to never exceed what
     * isVerified() actually supports, so this can't be gamed by the tier
     * alone).
     */
    private static function identityRisk(Company $company): int
    {
        if (! $company->isVerified()) {
            return 85;
        }

        $tier = $company->verification_tier instanceof VerificationTier
            ? $company->verification_tier
            : VerificationTier::from((int) ($company->verification_tier ?? 0));

        return match ($tier) {
            VerificationTier::Unverified => 70, // verified per the workflow but tier not yet asserted
            VerificationTier::IdentityVerified => 35,
            VerificationTier::OperationallyVerified => 25,
            VerificationTier::TraceabilityVerified => 15,
            VerificationTier::FieldVerified => 8,
            VerificationTier::LotIndependentlyInspected => 3,
        };
    }

    /**
     * REAL signal: CompanyDocument rows. More missing/expired documents (per
     * CompanyDocument::isExpired()) among what the company has actually
     * uploaded, and simply having zero documents on file at all, raise risk.
     */
    private static function documentationRisk(Company $company): int
    {
        $documents = $company->documents;

        if ($documents->isEmpty()) {
            return 80;
        }

        $expired = $documents->filter(fn (CompanyDocument $d) => $d->isExpired())->count();
        $pending = $documents->where('status', \App\Enums\DocumentStatus::Pending)->count();
        $total = $documents->count();

        $badRatio = ($expired + $pending) / max($total, 1);

        // A healthy documentation set floors near 10, a fully expired/pending
        // one rises to 90.
        return (int) round(10 + ($badRatio * 80));
    }

    /**
     * REAL signal, but a limited one: TimberLot rows belonging to this
     * company. No lots at all reads as elevated risk (nothing exists to
     * trace, so traceability cannot be evidenced either way).
     *
     * Honesty note: TimberLot's own `status` column is TimberLotStatus (an
     * availability/lifecycle state — Available/Reserved/etc.), NOT a
     * per-lot TraceabilityStatus value — this codebase does not currently
     * store a TraceabilityStatus on TimberLot itself. So the only genuine
     * traceability-adjacent signal available here is whether the company
     * has any structured, hash-chained lots on record at all (each carries
     * a lotEvents() ledger per the traceability blueprint) versus none.
     * Lots existing is scored lower than "no lots", but still short of a
     * confident low-risk score, because per-lot completeness isn't
     * evidenced by TimberLot alone.
     */
    private static function traceabilityRisk(Company $company): int
    {
        $lotCount = TimberLot::query()->forCompany($company->getKey())->count();

        return $lotCount === 0 ? 55 : 40;
    }

    /**
     * No dispute/complaint-tracking model exists anywhere in app/Models yet
     * (confirmed by search) — there is no real number to derive here. A
     * fabricated "low risk" default would be dishonest per the blueprint's
     * own evidence-before-claims principle, so this stays a documented
     * neutral middle value until such a system exists.
     */
    private static function disputeRisk(Company $company): int
    {
        unset($company);

        return self::NEUTRAL;
    }

    /**
     * REAL signal: open ComplianceCase rows against this company (polymorphic
     * owner). Cases sitting in a high-risk-adjacent status weigh more than
     * merely open/under-review ones. No cases at all is neutral-low (nothing
     * flagged is not the same evidence as "actively cleared").
     */
    private static function complianceRisk(Company $company): int
    {
        $cases = ComplianceCase::query()
            ->where('owner_type', Company::class)
            ->where('owner_id', $company->getKey())
            ->whereNull('closed_at')
            ->get(['status']);

        if ($cases->isEmpty()) {
            return 20;
        }

        $highRiskAdjacent = [
            ComplianceCaseStatus::HighRisk,
            ComplianceCaseStatus::RemediationRequired,
            ComplianceCaseStatus::Suspended,
        ];

        $severe = $cases->filter(fn (ComplianceCase $c) => in_array($c->status, $highRiskAdjacent, true))->count();
        $other = $cases->count() - $severe;

        $score = 20 + ($severe * 25) + ($other * 8);

        return (int) min(95, $score);
    }

    /**
     * REAL signal: published CompanyReview rows (rating_avg/rating_count on
     * Company, kept in sync by CompanyReviewService). A zero-review company
     * is genuinely unknown, not bad — it gets a fair neutral default rather
     * than being punished for simply being new. A low average rating with a
     * meaningful sample size raises risk; a strong rating with a meaningful
     * sample lowers it.
     */
    private static function reputationRisk(Company $company): int
    {
        if (! $company->hasRating()) {
            return self::NEUTRAL;
        }

        $avg = (float) $company->rating_avg; // 1-5 scale
        $count = (int) $company->rating_count;

        // Map a 1-5 average onto a 0-100 risk scale (5 stars -> ~5 risk,
        // 1 star -> ~95 risk), then pull scores from a thin sample back
        // toward neutral so a single bad/good review can't swing the score
        // as hard as a well-sampled one.
        $ratingRisk = (int) round((5 - $avg) / 4 * 90 + 5);
        $confidence = min(1.0, $count / 10);

        return (int) round(($ratingRisk * $confidence) + (self::NEUTRAL * (1 - $confidence)));
    }

    /**
     * WEAK-BUT-REAL signal: the supplier's own self-reported
     * on_time_delivery_percent. Explicitly labeled supplier-reported and NOT
     * independently verified (per earlier work today) — used here inversely
     * (higher on-time % -> lower risk) but only as a soft signal; absent
     * data reads as neutral, not as an assumed failure.
     */
    private static function deliveryRisk(Company $company): int
    {
        $percent = $company->on_time_delivery_percent;

        if ($percent === null) {
            return self::NEUTRAL;
        }

        $percent = max(0, min(100, (int) $percent));

        return (int) round(100 - $percent);
    }
}
