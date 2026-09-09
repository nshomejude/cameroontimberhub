<?php

namespace App\Domain\Logistics\Queries;

use App\Models\Shipment;
use App\Models\User;
use App\Services\BuyerApiScope;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves an order's shipment(s) plus each one's checkpoint history for the
 * buyer API.
 *
 * Ownership: mirrors OrderController::show() exactly — the order is
 * resolved through `BuyerApiScope::order()`, so another buyer's reference
 * 404s here the same way it does for `orders/{reference}` and
 * `orders/{reference}/trade-assurance` (never a 403 — see BuyerApiScope's
 * doc block on why that matters for reference-code enumeration).
 *
 * Checkpoint ordering: history is loaded oldest-first via the shared
 * `HasCheckpointUpdates` relation, ordered by `occurred_at` (then `id` to
 * break same-second ties) — the identical ordering rule `latestCheckpoint()`
 * uses, just ascending instead of descending. There is deliberately no
 * second, parallel checkpoint-ordering implementation here: this handler
 * only adds `orderBy` on top of the trait's own relation.
 */
final class GetOrderShipmentTrackingHandler implements HandlesQuery
{
    public function __construct(private readonly BuyerApiScope $scope) {}

    /** @return Collection<int, Shipment> */
    public function handle(Query $query): Collection
    {
        /** @var GetOrderShipmentTrackingQuery $query */
        $buyer = User::findOrFail($query->userId);

        $order = $this->scope->order($buyer, $query->reference);

        return Shipment::query()
            ->where('order_id', $order->getKey())
            ->with(['checkpointUpdates' => function ($relation) {
                $relation->orderBy('occurred_at')->orderBy('id');
            }])
            ->orderBy('created_at')
            ->get();
    }
}
