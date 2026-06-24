<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Species;
use App\Services\SearchService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CompanyDirectory extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $region = '';

    #[Url(except: '')]
    public string $species = '';

    #[Url(except: '')]
    public string $market = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRegion(): void
    {
        $this->resetPage();
    }

    public function updatingSpecies(): void
    {
        $this->resetPage();
    }

    public function updatingMarket(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->region = '';
        $this->species = '';
        $this->market = '';
        $this->resetPage();
    }

    public function render(SearchService $search): View
    {
        $companies = $search->searchCompanies([
            'q'       => $this->search,
            'region'  => $this->region,
            'species' => $this->species,
            'market'  => $this->market,
        ]);

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

        return view('livewire.company-directory', compact('companies', 'regions', 'speciesList', 'markets'));
    }
}
