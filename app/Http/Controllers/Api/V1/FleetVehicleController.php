<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\VehicleResource;
use App\Services\FleetApiScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A supplier/logistics company's own vehicle fleet over token auth — the API
 * counterpart of `Filament\Exporter\Resources\Vehicles\VehicleResource`.
 * Validation mirrors `Vehicles\Schemas\VehicleForm` exactly (same fields,
 * same rules), and scoping/eligibility both go through `FleetApiScope`, the
 * same company-ownership + fleet-eligibility boundary used by
 * `FleetDriverController`. See `FleetApiScope`'s docblock for why fleet
 * access is gated narrower than plain `api.supplier`.
 */
class FleetVehicleController extends Controller
{
    private const TYPES = ['truck', 'trailer', 'pickup', 'van', 'flatbed', 'container_chassis', 'other'];

    public function __construct(private readonly FleetApiScope $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $vehicles = $this->scope->vehicles($request->user())->paginate(15);

        return VehicleResource::collection($vehicles);
    }

    public function store(Request $request): VehicleResource
    {
        $this->scope->ensureEligible($request->user());
        $company = $this->scope->company($request->user());

        $data = $request->validate([
            'registration_number' => ['required', 'string', 'max:40'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'capacity_tonnes' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $vehicle = $company->vehicles()->create([
            'registration_number' => $data['registration_number'],
            'type' => $data['type'],
            'capacity_tonnes' => $data['capacity_tonnes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return new VehicleResource($vehicle);
    }

    public function show(Request $request, int|string $vehicle): VehicleResource
    {
        return new VehicleResource($this->scope->vehicle($request->user(), $vehicle));
    }

    public function update(Request $request, int|string $vehicle): VehicleResource
    {
        $record = $this->scope->vehicle($request->user(), $vehicle);

        $data = $request->validate([
            'registration_number' => ['sometimes', 'required', 'string', 'max:40'],
            'type' => ['sometimes', 'required', 'string', 'in:'.implode(',', self::TYPES)],
            'capacity_tonnes' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $record->update($data);

        return new VehicleResource($record->fresh());
    }
}
