<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $region = trim((string) $request->query('region', ''));
        $speciesSlug = trim((string) $request->query('species', ''));
        $market = trim((string) $request->query('market', ''));
        $term = trim((string) $request->query('q', ''));

        $companies = Company::publiclyVisible()
            ->with(['species:id,slug,common_name', 'exportMarkets:id,company_id,country_code'])
            ->when($region !== '', fn ($q) => $q->where('region', $region))
            ->when($speciesSlug !== '', fn ($q) => $q->whereHas('species', fn ($s) => $s->where('slug', $speciesSlug)))
            ->when($market !== '', fn ($q) => $q->whereHas('exportMarkets', fn ($m) => $m->where('country_code', $market)))
            ->when($term !== '', fn ($q) => $q->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$term]))
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->paginate(12)
            ->withQueryString();

        // Facet sources (only from publicly visible companies / published species).
        $regions = Company::publiclyVisible()
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region');

        $speciesList = Species::published()->orderBy('common_name')->get(['slug', 'common_name']);

        $markets = CompanyExportMarket::query()
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->distinct()
            ->orderBy('country_code')
            ->pluck('country_code');

        return view('public.companies.index', [
            'companies' => $companies,
            'regions' => $regions,
            'speciesList' => $speciesList,
            'markets' => $markets,
            'filters' => compact('region', 'speciesSlug', 'market', 'term'),
        ]);
    }
}
