<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Species;
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

    public function render(): View
    {
        $companies = Company::publiclyVisible()
            ->with(['species:id,slug,common_name', 'exportMarkets:id,company_id,country_code'])
            ->when($this->region !== '', fn ($q) => $q->where('region', $this->region))
            ->when($this->species !== '', fn ($q) => $q->whereHas('species', fn ($s) => $s->where('slug', $this->species)))
            ->when($this->market !== '', fn ($q) => $q->whereHas('exportMarkets', fn ($m) => $m->where('country_code', $this->market)))
            ->when($this->search !== '', fn ($q) => $q->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$this->search]))
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->paginate(12);

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
