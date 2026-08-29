<?php

namespace App\Http\Controllers\Public;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Brief §4.2's "Transformation Network" — a processor/manufacturer directory
 * kept deliberately separate from the timber-supplier directory
 * (DirectoryController/CompanyDirectory), plus the "Find a Transformer" flow
 * ("I have 100 m3 of Ayous" -> matched processors/manufacturers) built on
 * the already-shipped Capacity::scopeMatching() (gap-plan 1.5.4).
 *
 * Read-only against Company/OrganisationType/Capacity/Species -- no writes,
 * no schema changes.
 */
class TransformationNetworkController extends Controller
{
    /** The brief's business-type vocabulary (§4.2) -- Capacity.capability free-text values, not a new enum. */
    public const BUSINESS_TYPES = [
        'Sawing', 'Kiln drying', 'Planing', 'Moulding', 'Veneering', 'Laminating',
        'CNC', 'Joinery', 'Furniture', 'Doors', 'Flooring', 'Panels', 'Finishing', 'Packaging',
    ];

    public function index(Request $request): View
    {
        $type = (string) $request->query('type', '');
        $region = (string) $request->query('region', '');
        $capability = (string) $request->query('capability', '');

        $companies = $this->base()
            ->when(
                in_array($type, [OrganisationType::Processor->value, OrganisationType::Manufacturer->value], true),
                fn (Builder $q) => $q->where('type', $type)
            )
            ->when($region !== '', fn (Builder $q) => $q->where('region', $region))
            ->when($capability !== '', fn (Builder $q) => $q->whereHas(
                'capacities',
                fn (Builder $c) => $c->where('capability', 'ilike', "%{$capability}%")
            ))
            ->orderBy('legal_name')
            ->paginate(12)
            ->withQueryString();

        return view('public.transformation-network.index', [
            'companies' => $companies,
            'businessTypes' => self::BUSINESS_TYPES,
            'type' => $type,
            'region' => $region,
            'capability' => $capability,
        ]);
    }

    public function match(Request $request): View
    {
        $validated = $request->validate([
            'species' => ['nullable', 'string', 'exists:species,slug'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'period' => ['nullable', 'in:day,week,month,quarter,year'],
            'capability' => ['nullable', 'string'],
        ]);

        $species = $validated['species'] ?? null;
        $quantity = isset($validated['quantity']) ? (float) $validated['quantity'] : null;
        $period = $validated['period'] ?? 'month';
        $capability = $validated['capability'] ?? '';

        $matches = ($species !== null && $quantity !== null)
            ? $this->base()
                ->handlingSpecies([$species])
                ->whereHas('capacities', fn (Builder $c) => $c->matching($capability, $quantity, $period))
                ->orderBy('legal_name')
                ->get()
            : collect();

        return view('public.transformation-network.match', [
            'matches' => $matches,
            'speciesOptions' => Species::published()->orderBy('common_name')->get(['slug', 'common_name']),
            'species' => $species,
            'quantity' => $quantity,
            'period' => $period,
            'capability' => $capability,
        ]);
    }

    /** Verified processor/manufacturer companies -- the base set for both actions. */
    private function base(): Builder
    {
        return Company::query()
            ->whereIn('type', [OrganisationType::Processor->value, OrganisationType::Manufacturer->value])
            ->where('status', CompanyStatus::Verified->value);
    }
}
