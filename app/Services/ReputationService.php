<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Quote;
use App\Models\RfqCompany;

/**
 * Production-readiness plan Task C1 (blueprint §1.10).
 *
 * Rebuilds a company's reputation figures strictly from real rows —
 * completed orders, delivered orders, routed RFQs, submitted quotes and
 * formal disputes. Nothing here interpolates or seeds a number.
 *
 * Discipline mirrors the existing `eudr_risk_note` "not yet assessed"
 * behaviour: below a minimum sample the figure stays NULL, and the public
 * profile then shows "Not enough history yet" — never a fabricated 0%.
 */
class ReputationService
{
    /** Minimum delivered orders / routed RFQs before a percentage is trustworthy. */
    private const MIN_SAMPLE = 3;

    /** Trailing window for the response-rate calculation. */
    private const RESPONSE_WINDOW_DAYS = 90;

    public function recompute(Company $company): void
    {
        $companyId = $company->getKey();

        $company->forceFill([
            'orders_completed' => $this->ordersCompleted($companyId),
            'on_time_delivery_percent' => $this->onTimeDeliveryPercent($companyId),
            'response_rate_percent' => $this->responseRatePercent($companyId),
            'disputes_count' => $this->disputesCount($companyId),
            'reputation_recomputed_at' => now(),
        ])->saveQuietly();
    }

    private function ordersCompleted(int $companyId): int
    {
        return Order::query()
            ->where('company_id', $companyId)
            ->where('status', OrderStatus::Completed->value)
            ->count();
    }

    /**
     * round(100 * delivered-on-time / delivered-with-a-due-date).
     *
     * "delivered" = orders with a real `delivered_at`. On-time compares the
     * delivery date to `expected_delivery_at`; orders with no due date are
     * dropped from the denominator. NULL — not 0 — below MIN_SAMPLE delivered
     * orders, or when none of them carried a due date.
     */
    private function onTimeDeliveryPercent(int $companyId): ?int
    {
        $delivered = Order::query()
            ->where('company_id', $companyId)
            ->whereNotNull('delivered_at');

        if ((clone $delivered)->count() < self::MIN_SAMPLE) {
            return null;
        }

        $withDueDate = (clone $delivered)->whereNotNull('expected_delivery_at');
        $denominator = (clone $withDueDate)->count();

        if ($denominator === 0) {
            return null;
        }

        $onTime = (clone $withDueDate)
            ->whereRaw('delivered_at::date <= expected_delivery_at')
            ->count();

        return (int) round(100 * $onTime / $denominator);
    }

    /**
     * round(100 * quotes submitted / RFQs routed to the company) over the
     * trailing RESPONSE_WINDOW_DAYS. NULL below MIN_SAMPLE routed RFQs in
     * that window.
     */
    private function responseRatePercent(int $companyId): ?int
    {
        $routings = RfqCompany::query()
            ->where('company_id', $companyId)
            ->whereRaw('coalesce(routed_at, created_at) >= ?', [now()->subDays(self::RESPONSE_WINDOW_DAYS)]);

        $routedCount = (clone $routings)->count();

        if ($routedCount < self::MIN_SAMPLE) {
            return null;
        }

        $quoted = Quote::query()
            ->whereIn('rfq_company_id', (clone $routings)->pluck('id'))
            ->whereNotNull('submitted_at')
            ->distinct()
            ->count('rfq_company_id');

        return (int) round(100 * min($quoted, $routedCount) / $routedCount);
    }

    private function disputesCount(int $companyId): int
    {
        return Dispute::query()
            ->where('respondent_company_id', $companyId)
            ->count();
    }
}
