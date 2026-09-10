<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Queries\SearchProductCatalogueQuery;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\Api\V1\ProductDetailResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\ProductCatalogueService;
use App\Support\Bus\QueryBus;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public marketplace, read-only.
 *
 * Both actions read through ProductCatalogueService / the same visibility
 * predicate the web catalogue uses: `active` products belonging to companies
 * that pass Company::publiclyVisible(). Nothing here builds its own query from
 * scratch, so a draft listing or a hidden supplier cannot leak through the API
 * while staying hidden on the web.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductCatalogueService $catalogue,
        private readonly QueryBus $queryBus,
    ) {}

    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->filters();

        $products = $this->queryBus->dispatch(new SearchProductCatalogueQuery(
            filters: $filters,
            perPage: $request->perPage(),
        ));

        return ProductResource::collection($products)->additional([
            'meta' => [
                'facets' => [
                    'types' => $this->catalogue->typeFacets($filters),
                    'species' => $this->catalogue->speciesFacets($filters),
                    'regions' => $this->catalogue->regionFacets(),
                    'flags' => $this->catalogue->flagFacets($filters),
                ],
                'sort_options' => ProductCatalogueService::sortOptions(),
            ],
        ]);
    }

    public function show(string $slug): ProductDetailResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active)
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->with([
                'company.activeBadges',
                'company.exportMarkets',
                'company.contacts',
                'company.species:id,slug,common_name',
                'species',
                'images',
            ])
            ->firstOrFail();

        // "12 listings from this supplier" — counted over the public catalogue.
        $product->company?->loadCount(['products' => fn ($p) => $p->active()]);

        return new ProductDetailResource($product);
    }
}
