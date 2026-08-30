<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Species;
use App\Services\DomesticMarketplaceService;
use App\Support\CameroonGeography;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /buy-cameroon-wood — the domestic-market search experience (gap-plan
 * 1.5.2). Deliberately separate from ProductController/`/marketplace`: the
 * brief requires a search that never surfaces export vocabulary, so this
 * controller, its view and its filter language are domestic-only even
 * though both read the same underlying Product/Company data.
 */
class DomesticMarketplaceController extends Controller
{
    public function __construct(private readonly DomesticMarketplaceService $catalogue) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => (string) $request->query('q', ''),
            'types' => array_filter((array) $request->query('types', [])),
            'species' => array_filter((array) $request->query('species', [])),
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
            'typeFacets' => $this->catalogue->typeFacets($filters),
            'regionFacets' => $this->catalogue->regionFacets(),
            'cityOptionsByRegion' => $this->catalogue->cityOptionsByRegion(),
            'regionCoordinates' => collect(CameroonGeography::regions())->map(fn (array $d) => ['lat' => $d['lat'], 'lng' => $d['lng']])->all(),
            'sortOptions' => DomesticMarketplaceService::sortOptions(),
            'speciesOptions' => Species::published()->orderBy('common_name')->pluck('common_name', 'slug'),
            'typeOptions' => collect(ProductType::cases())->mapWithKeys(fn (ProductType $t) => [$t->value => $t->label()]),
        ]);
    }
}
