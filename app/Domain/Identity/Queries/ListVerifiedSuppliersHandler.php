<?php

namespace App\Domain\Identity\Queries;

use App\Services\SearchService;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Thin seam over SearchService::companyQuery()->paginate() — the same
 * chain SupplierController::index() ran inline. No query logic is
 * reimplemented; the visibility gate, filters and sort all stay in
 * SearchService.
 */
final class ListVerifiedSuppliersHandler implements HandlesQuery
{
    public function __construct(private readonly SearchService $search) {}

    public function handle(Query $query): LengthAwarePaginator
    {
        /** @var ListVerifiedSuppliersQuery $query */
        return $this->search
            ->companyQuery($query->filters)
            ->paginate($query->perPage);
    }
}
