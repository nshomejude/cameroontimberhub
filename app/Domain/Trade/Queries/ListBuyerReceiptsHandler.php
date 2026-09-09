<?php

namespace App\Domain\Trade\Queries;

use App\Models\User;
use App\Services\BuyerDashboard;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Extracted read behind AccountController::receipts(). Delegates to the
 * existing BuyerDashboard::receiptPage(), which is itself the single place
 * this query (eager loads, ordering, pagination) already lived — this
 * handler does not duplicate that logic, it just gives it a Query/Bus entry
 * point so the controller can stop querying directly.
 *
 * Kept as a full Query class (not inlined) for consistency with its three
 * siblings (rfqs/quotes/orders): AccountController's remaining read methods
 * are all identically shaped one-liners delegating to BuyerDashboard, so
 * treating one differently would just be an inconsistent seam for no
 * benefit — the API surface (Task Phase 1) needs the same read for
 * `/api/v1` receipts eventually anyway.
 */
final class ListBuyerReceiptsHandler implements HandlesQuery
{
    public function __construct(private readonly BuyerDashboard $dashboard) {}

    public function handle(Query $query): LengthAwarePaginator
    {
        /** @var ListBuyerReceiptsQuery $query */
        $user = User::findOrFail($query->userId);

        return $this->dashboard->receiptPage($user, $query->perPage);
    }
}
