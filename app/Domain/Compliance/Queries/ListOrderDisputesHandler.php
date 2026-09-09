<?php

namespace App\Domain\Compliance\Queries;

use App\Models\Order;
use App\Models\User;
use App\Services\DisputeService;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backs the API's `GET /orders/{reference}/disputes` — the token-auth
 * counterpart of `Public\DisputeController::index()`. Authorization is
 * re-derived from the order itself via `DisputeService::isOrderParty()`,
 * exactly like the web controller does: a non-party throws, never a silent
 * empty list, so the caller (the controller) can turn that into the same
 * 403 the web version returns.
 */
final class ListOrderDisputesHandler implements HandlesQuery
{
    public function __construct(private readonly DisputeService $disputes) {}

    /** @return Collection<int, \App\Models\Dispute> */
    public function handle(Query $query): Collection
    {
        /** @var ListOrderDisputesQuery $query */
        $order = Order::findOrFail($query->orderId);
        $user = User::findOrFail($query->userId);

        abort_unless($this->disputes->isOrderParty($order, $user), 403);

        $order->load(['disputes.raisedByUser', 'disputes.raisedByCompany', 'disputes.respondentCompany']);

        return $order->disputes;
    }
}
