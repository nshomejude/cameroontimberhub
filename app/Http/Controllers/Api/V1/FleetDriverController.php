<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DriverResource;
use App\Exceptions\Api\ConflictException;
use App\Services\FleetApiScope;
use App\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A supplier/logistics company's own drivers over token auth — the API
 * counterpart of `Filament\Exporter\Resources\Drivers\DriverResource`.
 * Validation mirrors `Drivers\Schemas\DriverForm` exactly. See
 * `FleetVehicleController`'s docblock — same conventions, same
 * `FleetApiScope` boundary.
 */
class FleetDriverController extends Controller
{
    public function __construct(
        private readonly FleetApiScope $scope,
        private readonly ShipmentService $shipments,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $drivers = $this->scope->drivers($request->user())->paginate(15);

        return DriverResource::collection($drivers);
    }

    public function store(Request $request): DriverResource
    {
        $this->scope->ensureEligible($request->user());
        $company = $this->scope->company($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $driver = $company->drivers()->create([
            'name' => $data['name'],
            'license_number' => $data['license_number'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return new DriverResource($driver);
    }

    public function show(Request $request, int|string $driver): DriverResource
    {
        return new DriverResource($this->scope->driver($request->user(), $driver));
    }

    public function update(Request $request, int|string $driver): DriverResource
    {
        $record = $this->scope->driver($request->user(), $driver);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'license_number' => ['sometimes', 'required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $record->update($data);

        return new DriverResource($record->fresh());
    }

    /** Hard delete (no SoftDeletes); 409 while on an in-transit shipment — see FleetVehicleController::destroy(). */
    public function destroy(Request $request, int|string $driver): Response
    {
        $record = $this->scope->driver($request->user(), $driver);

        if ($this->shipments->hasOpenAssignment('driver_id', $record->getKey())) {
            throw new ConflictException(__('logistics.errors.fleet_in_use'), 'fleet_in_use');
        }

        $record->delete();

        return response()->noContent();
    }
}
