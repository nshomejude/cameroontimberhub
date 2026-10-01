<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Logistics\Commands\RecordCheckpointCommand;
use App\Enums\OrderStatus;
use App\Enums\TrackingCheckpointStatus;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierShipmentResource;
use App\Models\CheckpointUpdate;
use App\Models\Shipment;
use App\Services\ShipmentService;
use App\Services\SupplierApiScope;
use App\Support\Bus\CommandBus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Shipments for the operator side of an order (logistics core loop): the
 * supplier who sold it and the carrier company moving it. Visibility and
 * checkpoint-write authority both go through ShipmentService::visibleTo()
 * (member of the carrier OR of the order's supplier company); creation is
 * supplier-only via SupplierApiScope::order(), the same scoping as
 * SupplierOrderController.
 */
class SupplierShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly SupplierApiScope $scope,
        private readonly CommandBus $commandBus,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $page = $this->shipments->visibleTo($request->user())
            ->with(['order', 'carrierCompany', 'vehicle', 'driver'])
            ->latest('id')
            ->paginate(15);

        return SupplierShipmentResource::collection($page);
    }

    public function show(Request $request, int $shipment): SupplierShipmentResource
    {
        return new SupplierShipmentResource($this->find($request, $shipment)->load([
            'order', 'carrierCompany', 'vehicle', 'driver',
            'checkpointUpdates' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id'),
        ]));
    }

    /** POST supplier/orders/{reference}/shipments — create a shipment + waybill. */
    public function storeForOrder(Request $request, string $reference): JsonResponse
    {
        $order = $this->scope->order($request->user(), $reference);

        if (in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            throw new ApiException(422, 'order_not_shippable', __('logistics.errors.order_not_shippable'));
        }

        $data = $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
            'driver_id' => ['nullable', 'integer'],
            'carrier_company_id' => ['nullable', 'integer'],
            'origin' => ['nullable', 'string', 'max:200'],
            'destination' => ['nullable', 'string', 'max:200'],
        ]);

        $shipment = $this->shipments->createFromOrder($order, $data);

        return (new SupplierShipmentResource($shipment->load(['order', 'carrierCompany', 'vehicle', 'driver'])))
            ->response()->setStatusCode(201);
    }

    /**
     * PATCH supplier/shipments/{id} — assign/replace vehicle, driver, carrier
     * and edit origin/destination (e.g. on the bare shipment auto-created
     * when the order was marked shipped). Supplier side only: a member of the
     * order's supplier company. A carrier member who can see the shipment
     * gets 403 `not_shipment_supplier`; anyone else 404. Same selection rules
     * as creation (ShipmentService::updateAssignment()).
     */
    public function update(Request $request, int $shipment): SupplierShipmentResource
    {
        $record = $this->find($request, $shipment);
        $companyIds = $request->user()->companies()->pluck('companies.id')->all();

        if (! in_array($record->order?->company_id, $companyIds, true)) {
            throw new ApiException(403, 'not_shipment_supplier', __('logistics.errors.shipment_supplier_only'));
        }

        $data = $request->validate([
            'vehicle_id' => ['sometimes', 'nullable', 'integer'],
            'driver_id' => ['sometimes', 'nullable', 'integer'],
            'carrier_company_id' => ['sometimes', 'nullable', 'integer'],
            'origin' => ['sometimes', 'nullable', 'string', 'max:200'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $this->shipments->updateAssignment($record, $data);

        return new SupplierShipmentResource($record->refresh()->load(['order', 'carrierCompany', 'vehicle', 'driver']));
    }

    /**
     * POST supplier/shipments/{id}/checkpoints — offline-replay safe: a
     * repeated `client_event_id` returns the originally created checkpoint
     * (200) instead of creating a duplicate (201).
     */
    public function storeCheckpoint(Request $request, int $shipment): JsonResponse
    {
        $record = $this->find($request, $shipment);

        $data = $request->validate([
            'status' => ['required', Rule::enum(TrackingCheckpointStatus::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
            'client_event_id' => ['nullable', 'string', 'max:100'],
            // Optional proof photo (multipart). Stored on the private local
            // disk: `photo_path` is never disclosed, only `has_photo`.
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);
        unset($data['photo']);

        if (($existing = $this->replayed($record, $data['client_event_id'] ?? null)) !== null) {
            return response()->json(['data' => SupplierShipmentResource::checkpoint($existing), 'replayed' => true], 200);
        }

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('checkpoint-photos/'.$record->getKey(), 'local')
            : null;

        try {
            $checkpoint = $this->commandBus->dispatch(new RecordCheckpointCommand($record, [
                ...$data,
                'photo_path' => $photoPath,
                'recorded_by' => $request->user()->getKey(),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent replay of the same client_event_id lost the race.
            $existing = $this->replayed($record, $data['client_event_id'] ?? null);
            if ($existing === null) {
                throw $e;
            }

            return response()->json(['data' => SupplierShipmentResource::checkpoint($existing), 'replayed' => true], 200);
        }

        $this->shipments->notifyCheckpointRecorded($record, $checkpoint, $request->user());

        return response()->json(['data' => SupplierShipmentResource::checkpoint($checkpoint), 'replayed' => false], 201);
    }

    private function replayed(Shipment $shipment, ?string $clientEventId): ?CheckpointUpdate
    {
        if ($clientEventId === null || $clientEventId === '') {
            return null;
        }

        return CheckpointUpdate::forTrackable($shipment)->where('client_event_id', $clientEventId)->first();
    }

    private function find(Request $request, int $id): Shipment
    {
        return $this->shipments->visibleTo($request->user())->whereKey($id)->firstOrFail();
    }
}
