<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $filters = [
            'q' => $q,
            'type' => (string) $request->query('type', 'products'),
            'species' => (string) $request->query('species', ''),
            'product_type' => (string) $request->query('product_type', ''),
            'grade' => (string) $request->query('grade', ''),
            'country' => (string) $request->query('country', ''),
            'sort' => (string) $request->query('sort', 'relevance'),
        ];

        $search = $this->search->crossSearch($filters, perPage: 12);

        return view('public.search', [
            'q' => $q,
            'filters' => $filters,
            'type' => in_array($filters['type'], SearchService::RESULT_TYPES, true) ? $filters['type'] : 'products',
            'results' => $search['results'],
            'counts' => $search['counts'],
            'supplierCount' => $search['supplierCount'],
            'sortOptions' => SearchService::searchSortOptions(),
            // Only pay for the "did you mean" query when the page is empty.
            'suggestions' => $q !== '' && $search['results']->total() === 0
                ? $this->search->suggestSpecies($q)
                : collect(),
            'speciesOptions' => Species::published()->orderBy('common_name')->pluck('common_name', 'slug'),
            'typeOptions' => collect(ProductType::cases())->mapWithKeys(fn (ProductType $t) => [$t->value => $t->label()]),
            'gradeOptions' => Product::query()->active()->whereNotNull('grade')
                ->distinct()->orderBy('grade')->pluck('grade', 'grade'),
            'regionOptions' => Company::publiclyVisible()->whereNotNull('region')
                ->distinct()->orderBy('region')->pluck('region', 'region'),
            'activeFilterCount' => count(array_filter([
                $filters['species'], $filters['product_type'], $filters['grade'], $filters['country'],
            ])),
        ]);
    }
}
