<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public Transformation Network read API — the JSON counterpart of
 * Public\TransformationNetworkController. This is a directory + matching
 * search only: verified Processor/Manufacturer companies, optionally
 * filtered by capability, plus the species+quantity+period match. There is
 * no request/accept/decline/quote/job pipeline in this codebase, so this
 * controller stays strictly read-only, mirroring the web controller's
 * queries.
 */
class TransformationNetworkController extends Controller
{
    /** The brief's business-type vocabulary — Capacity.capability free-text values. */
    public const BUSINESS_TYPES = \App\Http\Controllers\Public\TransformationNetworkController::BUSINESS_TYPES;

    public function index(Request $request): AnonymousResourceCollection
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
            ->paginate(min(max((int) $request->query('per_page', 12), 1), 48))
            ->withQueryString();

        return SupplierResource::collection($companies)->additional([
            'meta' => ['business_types' => self::BUSINESS_TYPES],
        ]);
    }

    public function show(string $slug): SupplierResource
    {
        $company = $this->base()
            ->where('slug', $slug)
            ->with(['species:id,slug,common_name', 'exportMarkets', 'activeBadges', 'contacts', 'capacities'])
            ->firstOrFail();

        return (new SupplierResource($company))->additional([
            'capacities' => $company->capacities->map(fn ($c) => [
                'id' => $c->id,
                'capability' => $c->capability,
                'quantity' => $c->quantity,
                'period' => $c->period,
            ])->values(),
        ]);
    }

    public function match(Request $request): AnonymousResourceCollection
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

        return SupplierResource::collection($matches);
    }

    /** Verified processor/manufacturer companies -- the base set for all actions. */
    private function base(): Builder
    {
        return Company::query()
            ->whereIn('type', [OrganisationType::Processor->value, OrganisationType::Manufacturer->value])
            ->where('status', CompanyStatus::Verified->value);
    }
}
