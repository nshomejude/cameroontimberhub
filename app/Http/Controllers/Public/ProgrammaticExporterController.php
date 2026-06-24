<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use Illuminate\View\View;

class ProgrammaticExporterController extends Controller
{
    /**
     * Route: GET /exporters/{species}   where {species} matches [a-z0-9-]+-cameroon
     *
     * Strips the -cameroon suffix to get the species slug, then renders a
     * programmatic-SEO page of verified exporters for that species.
     * Returns 404 when: species is unpublished, or zero publicly-visible
     * companies handle it (prevents thin/doorway pages).
     */
    public function show(string $species): View
    {
        $slug = preg_replace('/-cameroon$/', '', $species);

        $species = Species::published()->where('slug', $slug)->firstOrFail();

        $companies = Company::publiclyVisible()
            ->whereHas('species', fn ($q) => $q->whereKey($species->getKey()))
            ->with(['species:id,slug,common_name'])
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->paginate(12);

        abort_if($companies->isEmpty(), 404);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => "{$species->common_name} exporters from Cameroon",
            'url' => url()->current(),
            'numberOfItems' => $companies->total(),
        ];

        return view('public.pages.exporter-species', [
            'species' => $species,
            'companies' => $companies,
            'schema' => $schema,
        ]);
    }
}
