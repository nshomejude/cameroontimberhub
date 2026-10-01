<?php

namespace App\Services;

use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Open request" RFQ distribution: once an RFQ is approved, the suppliers who
 * handle what it asks for hear about it without staff hand-picking each one.
 *
 *  - autoRoute(): on approval, route to every company RfqMatchingService
 *    deems eligible (species/type targeting, verified, leads_receive),
 *    capped at `timber.rfq.auto_route_max`, through the existing
 *    RfqTriageService::routeDetailed() so leads, exporter notifications and
 *    the buyer's "sent to N suppliers" email all fire exactly as for a
 *    manual route. Toggle: `timber.rfq.auto_route_on_approval`.
 *  - autoApproveIfClean(): approve a verified RFQ whose RfqRiskService score
 *    is 0 and not spam (`timber.rfq.auto_approve_low_risk`).
 *  - selfRoute(): a supplier picking an RFQ off their "Buyer requests"
 *    board routes it to their own company (same routeDetailed() path).
 */
class RfqOpenRequestService
{
    public function __construct(
        private readonly RfqMatchingService $matching,
        private readonly LeadFlowService $leads,
    ) {}

    /** Route an approved RFQ to all matching suppliers. Returns how many were newly routed. */
    public function autoRoute(Rfq $rfq, ?User $actor = null): int
    {
        if (! config('timber.rfq.auto_route_on_approval', true) || $rfq->status !== RfqStatus::Approved || $rfq->is_spam
            || ! $this->matching->isOpenMarket($rfq)) {
            return 0;
        }

        try {
            $rfq->loadMissing('items.species');
            $ids = $this->matching
                ->eligibleCompanies($rfq, max(0, (int) config('timber.rfq.auto_route_max', 30)))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return 0;
            }

            return app(RfqTriageService::class)->routeDetailed($rfq, $ids, $actor, $this->leads)->routed;
        } catch (Throwable $e) {
            // Approval has already succeeded; staff can still route manually.
            Log::channel('errors')->error('RFQ auto-route failed', [
                'rfq_id' => $rfq->getKey(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** Auto-approve (and so auto-route) a verified RFQ the risk service found clean. */
    public function autoApproveIfClean(Rfq $rfq): bool
    {
        if (! config('timber.rfq.auto_approve_low_risk', true)
            || $rfq->status !== RfqStatus::New
            || $rfq->email_verified_at === null
            || $rfq->is_spam
            || (int) $rfq->spam_score > 0
            || ! $this->matching->isOpenMarket($rfq)) {
            return false;
        }

        app(RfqTriageService::class)->approve($rfq, null);

        return true;
    }

    /**
     * Route an RFQ on the company's open-requests board to that company so it
     * can quote. Returns false when the RFQ is not (or no longer) on the
     * board — callers 404 in that case. Already routed counts as success.
     */
    public function selfRoute(Rfq $rfq, Company $company, User $actor): bool
    {
        if ($rfq->routings()->where('company_id', $company->getKey())->exists()) {
            return true;
        }

        if (! $this->matching->isOpenRequestFor($rfq, $company)) {
            return false;
        }

        return app(RfqTriageService::class)->routeDetailed($rfq, [$company->getKey()], $actor, $this->leads)->routed > 0;
    }
}
