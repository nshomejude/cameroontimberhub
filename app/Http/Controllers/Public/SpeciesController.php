<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use Illuminate\View\View;

class SpeciesController extends Controller
{
    public function index(): View
    {
        $species = Species::published()
            ->orderBy('sort_order')
            ->orderBy('common_name')
            ->paginate(24);

        return view('public.species.index', ['species' => $species]);
    }

    public function show(string $slug): View
    {
        $species = Species::published()->where('slug', $slug)->firstOrFail();

        // "Verified exporters handling this species" — only publicly visible companies.
        $companies = Company::publiclyVisible()
            ->whereHas('species', fn ($q) => $q->whereKey($species->getKey()))
            ->with(['species:id,slug,common_name', 'exportMarkets:id,company_id,country_code'])
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->limit(24)
            ->get();

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Thing',
            'name' => $species->common_name,
            'alternateName' => $species->scientific_name,
            'url' => route('species.show', $species->slug),
            'description' => str(strip_tags((string) $species->description))->limit(300)->value(),
        ];

        return view('public.species.show', [
            'species' => $species,
            'companies' => $companies,
            'schema' => $schema,
        ]);
    }
}
