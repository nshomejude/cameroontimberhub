<?php

namespace App\Domain\Compliance\Queries;

use App\Support\Bus\Query;

/**
 * List the disputes raised on a single order. Party authorization is NOT
 * decided here — the handler re-derives it from the order itself, mirroring
 * `Http\Controllers\Public\DisputeController::index()` exactly (see the
 * handler's docblock).
 */
final class ListOrderDisputesQuery implements Query
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
    ) {}
}
