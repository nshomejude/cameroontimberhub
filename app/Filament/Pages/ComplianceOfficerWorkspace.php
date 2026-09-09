<?php

namespace App\Filament\Pages;

use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceRule;
use App\Models\RiskAssessment;
use App\Models\VerificationRevocationRequest;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Curated compliance-officer landing dashboard (implementation blueprint
 * §85): the small set of things a compliance officer cares about most --
 * open ComplianceCase counts by status, recently matched ComplianceRules,
 * companies whose latest RiskAssessment sits in a high-risk band, and any
 * pending two-person-control VerificationRevocationRequest approvals.
 *
 * Gated on the `compliance.manage` permission, matching every other
 * compliance-facing admin surface in this codebase (ComplianceCaseResource,
 * InspectionResource, InspectorResource, VerificationRevocationRequestResource).
 */
class ComplianceOfficerWorkspace extends Page
{
    protected string $view = 'filament.pages.compliance-officer-workspace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Compliance Workspace';

    protected static ?string $title = 'Compliance officer workspace';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    /** Open (not-closed) ComplianceCase rows grouped by status, worst first. */
    public function getOpenCasesByStatus(): Collection
    {
        return ComplianceCase::query()
            ->whereNull('closed_at')
            ->get(['status'])
            ->groupBy(fn (ComplianceCase $case) => $case->status->value)
            ->map->count()
            ->sortDesc();
    }

    /**
     * Recently updated, currently-active compliance rules.
     *
     * Honesty note: this codebase has no per-match audit trail for
     * ComplianceRule (no "last matched at" or match-log table exists — see
     * ComplianceRule::scopeApplicableTo(), which evaluates rules live and
     * records nothing). Rather than fabricate a "recent matches" feed, this
     * surfaces the real signal that exists: which active rules changed most
     * recently, which is what a compliance officer actually needs to review.
     */
    public function getRecentlyUpdatedRules(): Collection
    {
        return ComplianceRule::query()
            ->where('is_active', true)
            ->with('regulatorySource')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
    }

    /**
     * Companies whose most recent RiskAssessment sits in the two highest
     * bands. RiskAssessment rows are append-only history (never overwritten),
     * so we take each company's latest row via a per-company max(id).
     */
    public function getHighRiskCompanies(): Collection
    {
        $latestIds = RiskAssessment::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('company_id')
            ->pluck('id');

        return RiskAssessment::query()
            ->whereIn('id', $latestIds)
            ->whereIn('risk_band', ['high', 'critical'])
            ->with('company')
            ->orderByDesc('composite_score')
            ->limit(10)
            ->get();
    }

    /**
     * Pending VerificationRevocationRequest rows awaiting the second
     * approver. Defensively guarded even though the model exists today, to
     * mirror TimberPassportController's pattern for anything under
     * concurrent development elsewhere in the codebase.
     */
    public function getPendingRevocationRequests(): Collection
    {
        if (! class_exists(VerificationRevocationRequest::class)) {
            return collect();
        }

        try {
            return VerificationRevocationRequest::query()
                ->where('status', 'pending')
                ->with(['company', 'requestedBy'])
                ->latest('id')
                ->limit(10)
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    public function statusLabel(string $status): string
    {
        return ComplianceCaseStatus::tryFrom($status)?->label() ?? ucwords(str_replace('_', ' ', $status));
    }
}
