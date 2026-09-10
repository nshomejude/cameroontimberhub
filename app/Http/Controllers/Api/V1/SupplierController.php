<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Queries\ListVerifiedSuppliersQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SupplierIndexRequest;
use App\Http\Resources\Api\V1\SupplierDetailResource;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Company;
use App\Services\SearchService;
use App\Support\Bus\QueryBus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public supplier directory. Both actions go through
 * Company::publiclyVisible() — the listing via SearchService::companyQuery(),
 * the profile by re-applying the same scope — so an unverified or incomplete
 * company is a 404 here exactly as it is on the web.
 */
class SupplierController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
        private readonly QueryBus $queryBus,
    ) {}

    public function index(SupplierIndexRequest $request): AnonymousResourceCollection
    {
        $suppliers = $this->queryBus->dispatch(new ListVerifiedSuppliersQuery(
            filters: $request->filters(),
            perPage: $request->perPage(),
        ));

        return SupplierResource::collection($suppliers)->additional([
            'meta' => ['sort_options' => SearchService::companySortOptions()],
        ]);
    }

    public function show(string $slug): SupplierDetailResource
    {
        $company = Company::publiclyVisible()
            ->where('slug', $slug)
            ->with(['species:id,slug,common_name', 'exportMarkets', 'activeBadges', 'contacts'])
            ->withCount(['products' => fn ($p) => $p->active()])
            ->firstOrFail();

        return new SupplierDetailResource($company);
    }
}
