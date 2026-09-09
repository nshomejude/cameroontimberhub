<?php

namespace App\Domain\Trade\Queries;

use App\Support\Bus\Query;

/**
 * List a buyer's orders, paginated — backs AccountController::orders().
 */
final class ListBuyerOrdersQuery implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly int $perPage = 10,
    ) {}
}
