<?php

namespace App\Livewire;

use App\Enums\BadgeType;
use App\Enums\ProductType;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Product;
use App\Models\Species;
use App\Services\SearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Public supplier directory (/companies) — the faceted browse experience from
 * the approved desktop + mobile mockups. Every facet is a real query filter and
 * every count beside a checkbox is a real query count; nothing is hard-coded.
 *
 * All filter state lives in the URL so a filtered view is shareable and
 * survives a refresh.
 */
class CompanyDirectory extends Component
{
    use WithPagination;

    /** The facets the sidebar renders as counted checkbox lists. */
    private const FACET_LIMIT = 5;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> SupplierType values */
    #[Url(as: 'type', except: [])]
    public array $types = [];

    /** @var list<string> ProductType values */
    #[Url(as: 'spec', except: [])]
    public array $specs = [];

    /** @var list<string> Species slugs */
    #[Url(as: 'species', except: [])]
    public array $speciesIn = [];

    /** @var list<string> BadgeType values */
    #[Url(as: 'cert', except: [])]
    public array $certs = [];

    #[Url(except: '')]
    public string $region = '';

    /** Minimum years of trading experience; 0 = any. */
    #[Url(as: 'years', except: 0)]
    public int $minYears = 0;

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    #[Url(except: 12)]
    public int $perPage = 12;

    #[Url(except: 'grid')]
    public string $view = 'grid';

    /** Sidebar "search within facet" boxes — client-side narrowing, not URL state. */
    public string $specQuery = '';

    public string $speciesQuery = '';

    public function updated(string $property): void
    {
        // Any change to a filter invalidates the current page cursor.
        if (! in_array($property, ['view', 'specQuery', 'speciesQuery'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->types = [];
        $this->specs = [];
        $this->speciesIn = [];
        $this->certs = [];
        $this->region = '';
        $this->minYears = 0;
        $this->sort = 'featured';
        $this->resetPage();
    }

    /** Mobile chip row: a single-select shortcut over the multi-select type facet. */
    public function selectType(string $type = ''): void
    {
        $this->types = $type === '' ? [] : [$type];
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';
    }

    /** @return array{q: string, region: string, types: list<string>, specs: list<string>, speciesIn: list<string>, sort: string} */
    private function filters(): array
    {
        return [
            'q' => $this->search,
            'region' => $this->region,
            'types' => array_values($this->types),
            'specs' => array_values($this->specs),
            'speciesIn' => array_values($this->speciesIn),
            'certs' => array_values($this->certs),
            'minYears' => $this->minYears,
            'sort' => $this->sort,
        ];
    }

    /** True when any facet is engaged — drives the "Clear All" affordance. */
    public function getHasFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->types !== []
            || $this->specs !== []
            || $this->speciesIn !== []
            || $this->certs !== []
            || $this->region !== ''
            || $this->minYears > 0;
    }

    /**
     * Facet counts are computed against the free-text + region context only, so
     * ticking one box in a list does not zero out its siblings.
     *
     * @return array{q: string, region: string}
     */
    private function facetContext(): array
    {
        return ['q' => $this->search, 'region' => $this->region];
    }

    public function render(SearchService $search): View
    {
        $companies = $search->searchCompanies($this->filters(), max(1, min(48, $this->perPage)));

        $context = $this->facetContext();

        return view('livewire.company-directory', [
            'companies' => $companies,
            'typeFacets' => $this->typeFacets($search, $context),
            'specFacets' => $this->specFacets($search, $context),
            'speciesFacets' => $this->speciesFacets($search, $context),
            'certFacets' => $this->certFacets($search, $context),
            'experienceFacets' => $this->experienceFacets($search, $context),
            'regions' => $this->regionFacets($search, $context),
            'stats' => $this->stats(),
            'sortOptions' => SearchService::companySortOptions(),
            'facetLimit' => self::FACET_LIMIT,
        ]);
    }

    // ---- Facets ---------------------------------------------------------

    /** @return list<array{value: string, label: string, plural: string, icon: string, count: int}> */
    private function typeFacets(SearchService $search, array $context): array
    {
        $counts = $this->base($search, $context)
            ->whereNotNull('supplier_type')
            ->toBase()
            ->select('supplier_type')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('supplier_type')
            ->pluck('aggregate', 'supplier_type');

        return collect(SupplierType::cases())
            ->map(fn (SupplierType $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'plural' => $case->pluralLabel(),
                'icon' => $case->icon(),
                'count' => (int) ($counts[$case->value] ?? 0),
            ])
            ->all();
    }

    /** @return list<array{value: string, label: string, count: int}> */
    private function specFacets(SearchService $search, array $context): array
    {
        return collect(ProductType::cases())
            ->map(fn (ProductType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'count' => $this->base($search, $context)->withSpecialisation([$t->value])->count(),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0 || in_array($row['value'], $this->specs, true))
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string, count: int}> */
    private function speciesFacets(SearchService $search, array $context): array
    {
        return Species::published()
            ->orderBy('common_name')
            ->get(['id', 'slug', 'common_name'])
            ->map(fn (Species $s) => [
                'value' => $s->slug,
                'label' => $s->common_name,
                'count' => $this->base($search, $context)->handlingSpecies([$s->slug])->count(),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0 || in_array($row['value'], $this->speciesIn, true))
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string, count: int}> */
    private function certFacets(SearchService $search, array $context): array
    {
        return collect(BadgeType::cases())
            ->map(fn (BadgeType $b) => [
                'value' => $b->value,
                'label' => $b->label(),
                'count' => $this->base($search, $context)->withCertifications([$b->value])->count(),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0 || in_array($row['value'], $this->certs, true))
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return list<array{value: int, label: string, count: int}> */
    private function experienceFacets(SearchService $search, array $context): array
    {
        return collect([1, 5, 10, 20])
            ->map(fn (int $years) => [
                'value' => $years,
                'label' => $years === 1 ? '1+ years' : $years.'+ years',
                'count' => $this->base($search, $context)->minExperience($years)->count(),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    /** @return Collection<int, array{value: string, count: int}> */
    private function regionFacets(SearchService $search, array $context): Collection
    {
        return $this->base($search, $context)
            ->whereNotNull('region')
            ->toBase()
            ->select('region')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('region')
            ->orderBy('region')
            ->get()
            ->map(fn ($row) => ['value' => $row->region, 'count' => (int) $row->aggregate]);
    }

    /** @param array{q: string, region: string} $context */
    private function base(SearchService $search, array $context): Builder
    {
        return $search->companyFacetBase($context['q'], $context['region']);
    }

    /**
     * The four stat tiles. Real aggregates over the publicly visible set — dev
     * data simply yields smaller numbers than the mockup.
     *
     * @return list<array{icon: string, value: string, label: string}>
     */
    private function stats(): array
    {
        $verified = Company::publiclyVisible()->count();

        $countries = CompanyExportMarket::query()
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->distinct()
            ->count('country_code');

        $products = Product::query()
            ->active()
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->count();

        $responseRate = Company::publiclyVisible()
            ->whereNotNull('response_rate_percent')
            ->avg('response_rate_percent');

        $tiles = [
            ['icon' => 'user-group', 'value' => (string) $verified, 'label' => 'Verified Suppliers'],
            ['icon' => 'globe-europe-africa', 'value' => (string) $countries, 'label' => 'Countries Served'],
            ['icon' => 'squares-2x2', 'value' => (string) $products, 'label' => 'Products Available'],
        ];

        // Hide the tile entirely rather than print a fake average.
        if ($responseRate !== null) {
            $tiles[] = ['icon' => 'chat-bubble-left-right', 'value' => round((float) $responseRate).'%', 'label' => 'Response Rate'];
        }

        return $tiles;
    }
}
