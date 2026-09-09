<?php

namespace App\Domain\Logistics\Queries;

use App\Support\Bus\Query;

/**
 * Resolve the shipment/checkpoint history for one of a buyer's own orders —
 * backs OrderController::shipmentTracking() (API-First plan, Logistics
 * follow-up to Phase 1 §4's Order/Trade Assurance endpoints).
 *
 * `reference` is the order's `reference_code`, exactly like
 * ListBuyerOrdersQuery's sibling `show()`/`trade-assurance` endpoints key off
 * of. Ownership is enforced inside the handler via BuyerApiScope — the same
 * boundary OrderController::show()/TradeAssuranceController already use —
 * not here: a Query is just an intent, it carries no authorization itself.
 */
final class GetOrderShipmentTrackingQuery implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly string $reference,
    ) {}
}
