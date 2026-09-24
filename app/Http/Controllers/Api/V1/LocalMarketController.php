<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductDetailResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\SupplierDetailResource;
use App\Models\Company;
use App\Models\Product;
use App\Services\DomesticMarketplaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public "Buy Cameroon Wood" / local market read API — the JSON
 * counterpart of Public\DomesticMarketplaceController, wrapping the exact
 * same DomesticMarketplaceService query. There is no separate local-listing
 * or local-order model: a listing here IS a Product row (the same one
 * SupplierProductController manages), and a local order goes through the
 * same RFQ→Quote→Order pipeline as export — this controller is read-only.
 */
class LocalMarketController extends Controller
{
    public function __construct(private readonly DomesticMarketplaceService $catalogue) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $this->filters($request);

        $perPage = min(max((int) $request->query('per_page', 12), 1), 48);

        $products = $this->catalogue->search($filters, $perPage);

        return ProductResource::collection($products)->additional([
            'meta' => [
                'facets' => [
                    'categories' => $this->catalogue->categoryFacets($filters),
                    'regions' => $this->catalogue->regionFacets(),
                ],
                'sort_options' => DomesticMarketplaceService::sortOptions(),
            ],
        ]);
    }

    public function show(int $id): ProductDetailResource
    {
        $product = Product::query()
            ->where('products.id', $id)
            ->where('products.status', ProductStatus::Active->value)
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

        $product->company?->loadCount(['products' => fn ($p) => $p->active()]);

        return new ProductDetailResource($product);
    }

    public function yard(string $slug): SupplierDetailResource
    {
        $company = Company::publiclyVisible()
            ->where('slug', $slug)
            ->with(['species:id,slug,common_name', 'exportMarkets', 'activeBadges', 'contacts'])
            ->withCount(['products' => fn ($p) => $p->active()])
            ->firstOrFail();

        return new SupplierDetailResource($company);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'q' => (string) $request->query('q', ''),
            'categories' => array_values(array_filter((array) $request->query('categories', []))),
            'species' => array_values(array_filter((array) $request->query('species', []))),
            'grade' => (string) $request->query('grade', ''),
            'treatment' => (string) $request->query('treatment', ''),
            'region' => (string) $request->query('region', ''),
            'city' => (string) $request->query('city', ''),
            'minQuantity' => $request->query('quantity'),
            'maxThicknessMm' => $request->query('max_thickness'),
            'sort' => (string) $request->query('sort', 'newest'),
        ];
    }
}
