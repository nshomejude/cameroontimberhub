<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectoryController extends Controller
{
    public function index(Request $request, SearchService $search): View
    {
        // The Livewire component owns interactive filtering, but the ItemList
        // JSON-LD has to be present in the first server response for crawlers
        // and answer engines — so we resolve the same query here, from the same
        // URL parameters Livewire writes.
        $companies = $search->companyQuery([
            'q' => (string) $request->query('q', ''),
            'region' => (string) $request->query('region', ''),
            'types' => (array) $request->query('type', []),
            'specs' => (array) $request->query('spec', []),
            'speciesIn' => (array) $request->query('species', []),
            'sort' => (string) $request->query('sort', 'featured'),
        ])->limit(24)->get();

        $breadcrumbs = [
            ['label' => 'Home', 'url' => route('home')],
            ['label' => 'Suppliers', 'url' => route('directory')],
        ];

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Verified timber suppliers in Cameroon',
            'description' => 'Directory of verified Cameroonian timber suppliers, exporters and manufacturers.',
            'numberOfItems' => $companies->count(),
            'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
            'itemListElement' => $companies->values()->map(fn (Company $company, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => route('companies.show', $company->slug),
                'item' => array_filter([
                    '@type' => 'Organization',
                    'name' => $company->name,
                    'url' => route('companies.show', $company->slug),
                    'address' => array_filter([
                        '@type' => 'PostalAddress',
                        'addressLocality' => $company->city,
                        'addressRegion' => $company->region,
                        'addressCountry' => $company->country_code,
                    ]),
                ]),
            ])->all(),
        ];

        return view('public.companies.index', compact('breadcrumbs', 'schema'));
    }
}
