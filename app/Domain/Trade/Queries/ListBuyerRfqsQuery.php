<?php

namespace App\Domain\Trade\Queries;

use App\Support\Bus\Query;

/**
 * List a buyer's RFQs, paginated — backs AccountController::rfqs().
 */
final class ListBuyerRfqsQuery implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly int $perPage = 10,
    ) {}
}
