<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Species;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $featured = Company::publiclyVisible()
            ->with(['species:id,slug,common_name'])
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->limit(3)
            ->get();

        $species = Species::published()
            ->orderBy('sort_order')
            ->orderBy('common_name')
            ->limit(8)
            ->get(['slug', 'common_name']);

        $stats = [
            'companies' => Company::publiclyVisible()->count(),
            'species' => Species::published()->count(),
            'markets' => CompanyExportMarket::query()
                ->whereHas('company', fn ($c) => $c->publiclyVisible())
                ->distinct()
                ->count('country_code'),
        ];

        return view('home', compact('featured', 'species', 'stats'));
    }
}
