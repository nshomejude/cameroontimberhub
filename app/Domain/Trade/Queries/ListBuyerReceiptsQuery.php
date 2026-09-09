<?php

namespace App\Domain\Trade\Queries;

use App\Support\Bus\Query;

/**
 * List a buyer's order receipts, paginated — backs AccountController::receipts().
 */
final class ListBuyerReceiptsQuery implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly int $perPage = 10,
    ) {}
}
