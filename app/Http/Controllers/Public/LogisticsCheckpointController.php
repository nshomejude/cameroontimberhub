<?php

namespace App\Http\Controllers\Public;

use App\Domain\Logistics\Commands\RecordCheckpointCommand;
use App\Enums\TrackingCheckpointStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\ShipmentService;
use App\Support\Bus\CommandBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Offline-capable field checkpoint capture for logistics/drivers (blueprint
 * §45-46): recording a shipment checkpoint (dispatched/in-transit/delayed/
 * delivered) from the field, often with no connectivity (rural roads, border
 * crossings, ports).
 *
 * Plain Blade + fetch(), deliberately NOT Filament/Livewire — same reasoning
 * as the inspector capture flow: Livewire needs a live connection to the
 * server for every interaction, which does not work offline. The page's JS
 * uses resources/js/offline-queue.js's OfflineQueue.enqueue() to queue the
 * POST below when offline/failed, and syncs it automatically later; nothing
 * here needs to know that happened, since a queued+synced request just
 * arrives at `store()` late.
 *
 * Access control: the capture page sits behind `auth` (a guest is
 * redirected to login and back via the intended URL); the POST answers a
 * guest with a JSON 401 itself (a redirect would be followed by fetch() as
 * a 200 and the OfflineQueue would drop the item). The user must be a member of
 * the shipment's carrier company or the order's supplier company
 * (ShipmentService::canRecordCheckpoints()) — otherwise 403. Holding a
 * waybill number alone no longer lets anyone mark a shipment delivered.
 * Reading stays public (waybill page, /track/{token}). An unknown waybill
 * number still 404s via route model binding.
 */
class LogisticsCheckpointController extends Controller
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ShipmentService $shipments,
    ) {}

    public function create(Request $request, Shipment $shipment): View
    {
        abort_unless($this->shipments->canRecordCheckpoints($request->user(), $shipment), 403);

        return view('public.logistics.checkpoint', [
            'shipment' => $shipment,
            'statuses' => TrackingCheckpointStatus::cases(),
            'submitUrl' => route('logistics.checkpoints.store', $shipment),
        ]);
    }

    public function store(Request $request, Shipment $shipment): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['message' => __('logistics.errors.checkpoint_login_required')], 401);
        }

        if (! $this->shipments->canRecordCheckpoints($request->user(), $shipment)) {
            return response()->json(['message' => __('logistics.errors.checkpoint_forbidden')], 403);
        }

        // Built and validated manually (rather than $request->validate())
        // and returned as an explicit JsonResponse: bootstrap/app.php's
        // `shouldRenderJsonWhen()` only auto-renders JSON for `api/*`
        // requests, so on this web-group route a thrown ValidationException
        // would otherwise render as an HTML redirect — wrong for a fetch()/
        // OfflineQueue-driven endpoint that always expects JSON back.
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:'.implode(',', array_map(fn ($case) => $case->value, TrackingCheckpointStatus::cases()))],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Client-reported time the checkpoint actually happened (ISO
            // 8601, set by the form's JS at fill-in time) — distinct from
            // whenever this request happens to reach the server. See
            // CheckpointTracker::record() and the occurred_at migration doc.
            'occurred_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('logistics.errors.checkpoint_invalid'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $checkpoint = $this->commandBus->dispatch(new RecordCheckpointCommand($shipment, [
            'status' => $data['status'],
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'notes' => $data['notes'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
            'recorded_by' => $request->user()?->id,
        ]));

        return response()->json([
            'saved' => true,
            'checkpoint_id' => $checkpoint->id,
            'status' => $checkpoint->status->label(),
        ], 201);
    }
}
