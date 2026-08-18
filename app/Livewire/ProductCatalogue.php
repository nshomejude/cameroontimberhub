<?php

namespace App\Livewire;

use App\Services\ProductCatalogueService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Public timber marketplace (/marketplace) — the faceted catalogue browse,
 * built in the same language as the supplier and species directories.
 *
 * Every facet is a real query filter and every count beside a checkbox is a
 * real query count. All filter state lives in the URL, so a filtered view is
 * shareable and survives a refresh.
 */
class ProductCatalogue extends Component
{
    use WithPagination;

    private const FACET_LIMIT = 6;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * @var list<string> ProductType values
     *
     * Aliased to plural `types` so the singular `?type=logs` deep link (used by
     * the landing page and by external links) stays a scalar param that mount()
     * normalises, rather than colliding with this array binding.
     */
    #[Url(as: 'types', except: [])]
    public array $types = [];

    /** @var list<string> species slugs */
    #[Url(as: 'wood', except: [])]
    public array $speciesIn = [];

    #[Url(except: '')]
    public string $region = '';

    #[Url(as: 'certified', except: false)]
    public bool $certifiedOnly = false;

    #[Url(as: 'best', except: false)]
    public bool $bestSellers = false;

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    #[Url(except: 12)]
    public int $perPage = 12;

    #[Url(except: 'grid')]
    public string $view = 'grid';

    /** Sidebar-local facet searches (not URL state). */
    public string $typeQuery = '';

    public string $speciesQuery = '';

    /**
     * Legacy / inbound deep links use singular scalar params
     * (`/marketplace?type=logs&species=iroko`). Fold them into the multi-select
     * facets so an external link lands on the same filtered view.
     */
    public function mount(): void
    {
        $type = (string) request()->query('type', '');
        if ($type !== '' && $this->types === []) {
            $this->types = [$type];
        }

        $species = (string) request()->query('species', '');
        if ($species !== '' && $this->speciesIn === []) {
            $this->speciesIn = [$species];
        }
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['view', 'typeQuery', 'speciesQuery'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->types = [];
        $this->speciesIn = [];
        $this->region = '';
        $this->certifiedOnly = false;
        $this->bestSellers = false;
        $this->sort = 'featured';
        $this->resetPage();
    }

    /** Mobile chip row: single-select shortcut over the multi-select type facet. */
    public function selectType(string $type = ''): void
    {
        $this->types = $type === '' ? [] : [$type];
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';
    }

    public function getHasFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->types !== []
            || $this->speciesIn !== []
            || $this->region !== ''
            || $this->certifiedOnly
            || $this->bestSellers;
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'q' => $this->search,
            'types' => array_values($this->types),
            'speciesIn' => array_values($this->speciesIn),
            'region' => $this->region,
            'certifiedOnly' => $this->certifiedOnly,
            'bestSellers' => $this->bestSellers,
            'sort' => $this->sort,
        ];
    }

    public function render(ProductCatalogueService $catalogue): View
    {
        $filters = $this->filters();
        $products = $catalogue->search($filters, max(1, min(48, $this->perPage)));

        return view('livewire.product-catalogue', [
            'products' => $products,
            'typeFacets' => $catalogue->typeFacets($filters),
            'speciesFacets' => $catalogue->speciesFacets($filters),
            'regions' => $catalogue->regionFacets(),
            'flagFacets' => $catalogue->flagFacets($filters),
            'sortOptions' => ProductCatalogueService::sortOptions(),
            'facetLimit' => self::FACET_LIMIT,
            'catalogueCount' => $catalogue->base()->count(),
        ]);
    }
}
