<?php

namespace App\Domain\Trade\Queries;

use App\Models\User;
use App\Services\BuyerDashboard;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Extracted read behind AccountController::orders(). Delegates to the
 * existing BuyerDashboard::orderPage(), which is itself the single place
 * this query (eager loads, ordering, pagination) already lived — this
 * handler does not duplicate that logic, it just gives it a Query/Bus entry
 * point so the controller can stop querying directly.
 */
final class ListBuyerOrdersHandler implements HandlesQuery
{
    public function __construct(private readonly BuyerDashboard $dashboard) {}

    public function handle(Query $query): LengthAwarePaginator
    {
        /** @var ListBuyerOrdersQuery $query */
        $user = User::findOrFail($query->userId);

        return $this->dashboard->orderPage($user, $query->perPage);
    }
}
