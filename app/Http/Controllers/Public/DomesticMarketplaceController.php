<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Species;
use App\Services\DomesticMarketplaceService;
use App\Support\CameroonGeography;
use App\Support\CategoryMigrationMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /buy-cameroon-wood — the domestic-market search experience (gap-plan
 * 1.5.2). Deliberately separate from ProductController/`/marketplace`: the
 * brief requires a search that never surfaces export vocabulary, so this
 * controller, its view and its filter language are domestic-only even
 * though both read the same underlying Product/Company data.
 *
 * Listings are faceted by the `form`-kind Category tree (1.5.2b). Legacy
 * `?product_type=` / `?types[]=` links are 301-redirected to the equivalent
 * `?categories[]=` via App\Support\CategoryMigrationMap so old bookmarks
 * keep resolving.
 */
class DomesticMarketplaceController extends Controller
{
    public function __construct(private readonly DomesticMarketplaceService $catalogue) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectLegacyTypeParams($request)) {
            return $redirect;
        }

        $filters = [
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

        $products = $this->catalogue->search($filters, 12);

        return view('public.domestic.index', [
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Buy Cameroon Wood', 'url' => route('domestic.marketplace')],
            ],
            'products' => $products,
            'filters' => $filters,
            'categoryFacets' => $this->catalogue->categoryFacets($filters),
            'regionFacets' => $this->catalogue->regionFacets(),
            'cityOptionsByRegion' => $this->catalogue->cityOptionsByRegion(),
            'regionCoordinates' => collect(CameroonGeography::regions())->map(fn (array $d) => ['lat' => $d['lat'], 'lng' => $d['lng']])->all(),
            'sortOptions' => DomesticMarketplaceService::sortOptions(),
            'speciesOptions' => Species::published()->orderBy('common_name')->pluck('common_name', 'slug'),
        ]);
    }

    /**
     * Translate pre-1.5.2b `?product_type=` / `?types[]=` links (ProductType
     * values) into `?categories[]=` (form-category slugs) and 301 to the
     * canonical URL. Returns null when there is nothing legacy to rewrite.
     */
    private function redirectLegacyTypeParams(Request $request): ?RedirectResponse
    {
        if ($request->query('categories') !== null) {
            return null;
        }

        $legacy = array_filter(array_merge(
            (array) $request->query('product_type', []),
            (array) $request->query('types', []),
        ), fn ($v) => is_string($v) && $v !== '');

        if ($legacy === []) {
            return null;
        }

        $slugs = collect($legacy)
            ->map(fn (string $type) => CategoryMigrationMap::MAP[$type] ?? null)
            ->filter()
            ->unique()
            ->values();

        $params = $request->except(['product_type', 'types']);

        if ($slugs->isNotEmpty()) {
            $params['categories'] = $slugs->all();
        }

        return redirect()->route('domestic.marketplace', $params, 301);
    }
}
