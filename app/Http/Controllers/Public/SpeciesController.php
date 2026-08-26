<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use App\Services\SpeciesDirectoryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SpeciesController extends Controller
{
    /**
     * The directory itself is a Livewire component; the controller's job is the
     * server-rendered SEO/AEO layer. It reads the same URL query state the
     * Livewire component mounts from and runs it through the same service, so
     * the ItemList always describes exactly the page the visitor is looking at.
     */
    public function index(Request $request, SpeciesDirectoryService $directory): View
    {
        $perPage = max(1, min(48, (int) ($request->query('perPage') ?: 12)));

        $species = $directory->search($this->filtersFromRequest($request), $perPage);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Cameroon timber species directory',
            'url' => url()->current(),
            'numberOfItems' => $species->total(),
            'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
            'itemListElement' => $species->values()->map(fn (Species $sp, int $i): array => array_filter([
                '@type' => 'ListItem',
                'position' => ($species->firstItem() ?? 1) + $i,
                'name' => $sp->common_name,
                'url' => route('species.show', $sp->slug),
                'alternateName' => $sp->scientific_name,
            ]))->all(),
        ];

        return view('public.species.index', [
            'schema' => $schema,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Timber Species', 'url' => route('species.index')],
            ],
        ]);
    }

    /**
     * Mirrors the `#[Url]` aliases on App\Livewire\SpeciesDirectory.
     *
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request): array
    {
        $list = fn (string $key): array => array_values(array_filter(
            array_map('strval', (array) $request->query($key, [])),
            fn (string $v): bool => $v !== ''
        ));

        return [
            'q' => (string) $request->query('q', ''),
            'categories' => $list('category'),
            'properties' => $list('property'),
            'applications' => $list('use'),
            'region' => (string) $request->query('region', ''),
            'inStock' => $request->boolean('stock'),
            'sort' => (string) $request->query('sort', 'popularity'),
        ];
    }

    public function show(string $slug): View
    {
        $species = Species::published()->where('slug', $slug)->firstOrFail();

        // "Verified exporters handling this species" — only publicly visible companies.
        $companies = Company::publiclyVisible()
            ->whereHas('species', fn ($q) => $q->whereKey($species->getKey()))
            ->with([
                'species:id,slug,common_name',
                'exportMarkets:id,company_id,country_code',
                'products' => fn ($p) => $p->active()->select('id', 'company_id', 'product_type'),
            ])
            ->withCount(['products' => fn ($p) => $p->active()])
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
