<?php

namespace App\Livewire;

use App\Models\Species;
use App\Services\SpeciesDirectoryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Public timber species directory (/species) — the faceted browse experience
 * from the approved desktop + mobile mockups.
 *
 * Every facet is a real query filter and every count beside a checkbox is a
 * real query count. All filter state lives in the URL, so a filtered view is
 * shareable and survives a refresh.
 */
class SpeciesDirectory extends Component
{
    use WithPagination;

    /** Applications list is long enough to need a "View More" affordance. */
    private const FACET_LIMIT = 5;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> TimberCategory values */
    #[Url(as: 'category', except: [])]
    public array $categories = [];

    /** @var list<string> SpeciesDirectoryService property facet keys */
    #[Url(as: 'property', except: [])]
    public array $properties = [];

    /** @var list<string> SpeciesDirectoryService application facet keys */
    #[Url(as: 'use', except: [])]
    public array $applications = [];

    #[Url(except: '')]
    public string $region = '';

    #[Url(as: 'stock', except: false)]
    public bool $inStock = false;

    #[Url(except: 'popularity')]
    public string $sort = 'popularity';

    #[Url(except: 12)]
    public int $perPage = 12;

    #[Url(except: 'grid')]
    public string $view = 'grid';

    public function updated(string $property): void
    {
        // Any change to a filter invalidates the current page cursor.
        if ($property !== 'view') {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->categories = [];
        $this->properties = [];
        $this->applications = [];
        $this->region = '';
        $this->inStock = false;
        $this->sort = 'popularity';
        $this->resetPage();
    }

    /** Mobile chip row: a single-select shortcut over the multi-select category facet. */
    public function selectCategory(string $category = ''): void
    {
        $this->categories = $category === '' ? [] : [$category];
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';
    }

    /** True when any facet is engaged — drives the "Clear All" affordance. */
    public function getHasFiltersProperty(): bool
    {
        return $this->search !== ''
            || $this->categories !== []
            || $this->properties !== []
            || $this->applications !== []
            || $this->region !== ''
            || $this->inStock;
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'q' => $this->search,
            'categories' => array_values($this->categories),
            'properties' => array_values($this->properties),
            'applications' => array_values($this->applications),
            'region' => $this->region,
            'inStock' => $this->inStock,
            'sort' => $this->sort,
        ];
    }

    public function render(SpeciesDirectoryService $directory): View
    {
        $species = $directory->search($this->filters(), max(1, min(48, $this->perPage)));

        $context = ['q' => $this->search, 'region' => $this->region];

        return view('livewire.species-directory', [
            'species' => $species,
            'categoryFacets' => $directory->categoryFacets($context, $this->categories),
            'propertyFacets' => $directory->facets('properties', $context, $this->properties),
            'applicationFacets' => $directory->facets('applications', $context, $this->applications),
            'inStockFacet' => $directory->inStockFacet($context),
            'regions' => $directory->regionFacets(),
            'sortOptions' => SpeciesDirectoryService::sortOptions(),
            'facetLimit' => self::FACET_LIMIT,
            'publishedCount' => Species::published()->count(),
        ]);
    }
}
