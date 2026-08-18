<?php

namespace App\Http\Controllers\Public;

use App\Enums\TimberCategory;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SpeciesController extends Controller
{
    public function index(Request $request): View
    {
        $category = TimberCategory::tryFrom((string) $request->query('category'));
        $promotedOnly = $request->boolean('promoted');

        $species = Species::published()
            ->when($category, fn ($q) => $q->inCategory($category))
            ->when($promotedOnly, fn ($q) => $q->promoted())
            ->orderBy('sort_order')
            ->orderBy('common_name')
            ->paginate(24)
            ->withQueryString();

        // Facet counts come from the published set, not the filtered one, so the
        // chips keep showing every category even while one is active.
        $categoryCounts = Species::published()
            ->selectRaw('commercial_category, count(*) as aggregate')
            ->whereNotNull('commercial_category')
            ->groupBy('commercial_category')
            ->pluck('aggregate', 'commercial_category');

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Cameroon timber species',
            'numberOfItems' => $species->total(),
            'itemListElement' => $species->values()->map(fn (Species $sp, int $i): array => [
                '@type' => 'ListItem',
                'position' => $species->firstItem() + $i,
                'name' => $sp->common_name,
                'url' => route('species.show', $sp->slug),
            ])->all(),
        ];

        return view('public.species.index', [
            'species' => $species,
            'categories' => TimberCategory::cases(),
            'categoryCounts' => $categoryCounts,
            'activeCategory' => $category,
            'promotedOnly' => $promotedOnly,
            'promotedCount' => Species::published()->promoted()->count(),
            'schema' => $schema,
        ]);
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

        return view('public.species.show', [
            'species' => $species,
            'companies' => $companies,
            'schema' => $this->schemaFor($species),
        ]);
    }

    /** @return array<string, mixed> */
    private function schemaFor(Species $species): array
    {
        $properties = [];

        $add = function (string $name, ?string $value) use (&$properties): void {
            if (filled($value)) {
                $properties[] = ['@type' => 'PropertyValue', 'name' => $name, 'value' => $value];
            }
        };

        $add('Scientific name', $species->scientific_name);
        $add('Family', $species->family);
        $add('Commercial category', $species->commercial_category?->label());
        $add('Density', $species->densityRange());
        $add('Durability class', $species->durability_class);
        $add('Janka hardness', $species->janka_hardness ? $species->janka_hardness.' N' : null);
        $add('Typical uses', $species->typical_uses ? implode(', ', $species->typical_uses) : null);
        $add('Regions harvested', $species->region_availability ? implode(', ', $species->region_availability) : null);
        $add('CITES appendix', $species->is_cites_listed ? ($species->cites_appendix ?: 'Listed') : null);

        $alternateNames = array_values(array_filter(array_merge(
            [$species->scientific_name],
            $species->trade_names ?? [],
            $species->local_names ?? [],
        )));

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Thing',
            'name' => $species->common_name,
            'alternateName' => $alternateNames ?: null,
            'url' => route('species.show', $species->slug),
            'description' => str(strip_tags((string) $species->description))->limit(300)->value(),
            'additionalProperty' => $properties ?: null,
        ]);
    }
}
