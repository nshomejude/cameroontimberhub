<?php

namespace App\Domain\Trade\Queries;

use App\Support\Bus\Query;

/**
 * List a buyer's received quotes, paginated — backs AccountController::quotes().
 */
final class ListBuyerQuotesQuery implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly int $perPage = 10,
    ) {}
}
