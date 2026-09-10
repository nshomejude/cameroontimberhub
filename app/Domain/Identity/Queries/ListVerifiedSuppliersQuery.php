<?php

namespace App\Domain\Identity\Queries;

use App\Support\Bus\Query;

/**
 * The public supplier directory listing — backs the API's
 * `GET /api/v1/suppliers` (Api\V1\SupplierController::index).
 *
 * `$filters` is the array SearchService::companyQuery() expects (see
 * Http\Requests\Api\V1\SupplierIndexRequest::filters()). The
 * publicly-visible gate lives in SearchService::companyQuery(), which the
 * handler delegates to unchanged — an unverified/incomplete company is
 * absent here exactly as on the web.
 */
final class ListVerifiedSuppliersQuery implements Query
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public readonly array $filters,
        public readonly int $perPage = 12,
    ) {}
}
