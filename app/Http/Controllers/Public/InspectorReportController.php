<?php

namespace App\Http\Controllers\Public;

use App\Domain\Compliance\Commands\FinaliseInspectionCommand;
use App\Http\Controllers\Controller;
use App\Models\Inspection;
use App\Support\Bus\CommandBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Mobile-first, offline-capable field data capture for inspectors (blueprint
 * §45-46): filling in and submitting an inspection report/checklist while
 * physically at a timber site with no or poor connectivity.
 *
 * Plain Blade (no Livewire) on purpose — Livewire's request/DOM-diffing
 * protocol does not survive being replayed later by
 * resources/js/offline-queue.js's OfflineQueue, whereas a plain HTML form
 * posting JSON does. The page is precached by the service worker
 * (public/sw.js) once visited, and its submit handler enqueues the POST via
 * OfflineQueue.enqueue() when navigator.onLine is false or the live fetch
 * fails, so the inspector always gets a clear "queued" confirmation instead
 * of a silent failure.
 *
 * Access is scoped to the specific Inspector assigned to the Inspection —
 * re-derived from the Inspection's own inspector_id -> Inspector -> user_id
 * chain on every request, never trusted from the route alone (same pattern
 * as DisputeController::isOrderParty()).
 */
class InspectorReportController extends Controller
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function edit(Request $request, Inspection $inspection): View
    {
        $this->authorizeInspector($request, $inspection);

        return view('public.inspector.report', [
            'inspection' => $inspection->load('timberLot'),
            'submitUrl' => route('inspector.inspections.report.store', $inspection),
        ]);
    }

    /**
     * Accepts the same payload shape whether it arrives live (submitted
     * online) or replayed later from the offline queue (submitted while
     * offline, synced minutes or hours afterward). Because a queued
     * submission can be stale by the time it syncs, business rules —
     * not just field validation — are re-checked here, every time.
     */
    public function store(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorizeInspector($request, $inspection);

        // Built and checked manually (not $request->validate()): this app's
        // exception renderer (bootstrap/app.php shouldRenderJsonWhen) only
        // renders JSON for `api/*` requests, so a thrown ValidationException
        // here would redirect instead of returning JSON — useless both for a
        // live fetch() and for a replayed OfflineQueue item, which need a
        // real JSON error response to surface to the inspector.
        $validator = Validator::make($request->all(), [
            'performed_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'observed_quantity' => ['nullable', 'numeric', 'min:0'],
            'quality_findings' => ['nullable', 'string', 'max:4000'],
            'species_findings' => ['nullable', 'string', 'max:4000'],
            'packaging_findings' => ['nullable', 'string', 'max:4000'],
            'result' => ['required', 'string', 'in:pass,fail,conditional'],
            'inspector_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'This report could not be submitted. Please check the form and try again.',
                'code' => 'validation_failed',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        // Re-validate the business rule server-side: an offline-queued
        // submission may sync well after the inspection was already
        // finalised (e.g. amended by staff, or double-submitted from two
        // devices) — never blindly trust client state at replay time.
        if ($inspection->finalised_at !== null) {
            return response()->json([
                'message' => 'This inspection has already been finalised and can no longer be submitted this way. Contact compliance to request an amendment.',
                'code' => 'already_finalised',
            ], 422);
        }

        try {
            $this->commandBus->dispatch(new FinaliseInspectionCommand(
                inspectionId: $inspection->getKey(),
                data: $data,
            ));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'finalise_failed',
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not save this report. Please try again.',
                'code' => 'server_error',
            ], 500);
        }

        return response()->json([
            'message' => 'Inspection report finalised.',
            'inspection_id' => $inspection->getKey(),
        ], 200);
    }

    private function authorizeInspector(Request $request, Inspection $inspection): void
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        $inspection->loadMissing('inspector');

        abort_unless(
            $inspection->inspector !== null && $inspection->inspector->user_id === $user->getKey(),
            403
        );
    }
}
