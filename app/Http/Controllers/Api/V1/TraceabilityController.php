<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TimberLotStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\TimberLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (no-auth) "CTH Timber Passport" JSON API — the mobile counterpart
 * of Public\TimberPassportController. Mirrors that controller's exact
 * disclosure rule (a lot 404s unless its company is
 * Company::scopePubliclyVisible() and its status isn't Draft) and its exact
 * field set (resources/views/public/passport/show.blade.php), reshaped as
 * JSON rather than a Blade view.
 */
class TraceabilityController extends Controller
{
    public function passport(string $lotNumber): JsonResponse
    {
        $lot = TimberLot::query()
            ->where('lot_number', $lotNumber)
            ->where('status', '!=', TimberLotStatus::Draft->value)
            ->whereHas('company', fn (Builder $q) => $q->publiclyVisible())
            ->with(['company', 'species', 'product'])
            ->first();

        if (! $lot) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $events = $this->events($lot);
        $transformations = $this->transformations($lot);

        return response()->json([
            'data' => [
                'lot_number' => $lot->lot_number,
                'status' => $lot->status->value,
                'status_label' => $lot->status->label(),
                'species' => $lot->species ? [
                    'slug' => $lot->species->slug,
                    'common_name' => $lot->species->common_name,
                ] : null,
                'product_form' => $lot->product_form,
                'volume_m3' => $lot->volume_m3 !== null ? (float) $lot->volume_m3 : null,
                'origin_country' => $lot->origin_country,
                'origin_region' => $lot->origin_region,
                'origin_latitude' => $lot->origin_latitude !== null ? (float) $lot->origin_latitude : null,
                'origin_longitude' => $lot->origin_longitude !== null ? (float) $lot->origin_longitude : null,
                'origin_boundary' => $lot->origin_boundary,
                'harvest_period_start' => $lot->harvest_period_start?->toDateString(),
                'harvest_period_end' => $lot->harvest_period_end?->toDateString(),
                'processing_site' => $lot->processing_site,
                'chain_of_custody' => [
                    'events' => $events?->map(fn ($e) => [
                        'event_type' => $e->event_type->value,
                        'occurred_at' => $e->occurred_at?->toIso8601String(),
                        'location' => $e->location,
                        'quantity_before' => $e->quantity_before,
                        'quantity_after' => $e->quantity_after,
                        'notes' => $e->notes,
                    ])->values(),
                    'transformations' => $transformations?->map(fn ($t) => [
                        'transformation_type' => $t->transformation_type,
                        'input_volume_m3' => $t->input_volume_m3 !== null ? (float) $t->input_volume_m3 : null,
                        'output_volume_m3' => $t->output_volume_m3 !== null ? (float) $t->output_volume_m3 : null,
                        'loss_volume_m3' => $t->loss_volume_m3 !== null ? (float) $t->loss_volume_m3 : null,
                        'processed_at' => $t->processed_at?->toIso8601String(),
                    ])->values(),
                ],
                'passport_url' => route('passport.show', $lot),
                'barcode_value' => $lot->lot_number,
            ],
        ]);
    }

    /**
     * Defensively load traceability events, exactly like
     * Public\TimberPassportController::loadTraceabilityEvents().
     */
    private function events(TimberLot $lot): ?\Illuminate\Support\Collection
    {
        if (! class_exists(\App\Models\LotEvent::class)) {
            return null;
        }

        try {
            $events = $lot->lotEvents()->orderBy('id')->get();

            return $events->isNotEmpty() ? $events : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Defensively load mass-balance transformation history, exactly like
     * Public\TimberPassportController::loadTransformationHistory().
     */
    private function transformations(TimberLot $lot): ?\Illuminate\Support\Collection
    {
        if (! class_exists(\App\Models\LotTransformation::class)) {
            return null;
        }

        try {
            $inputs = $lot->inputTransformations()->get();
            $outputs = $lot->outputTransformations()->get();

            $combined = $inputs->merge($outputs)->unique('id');

            return $combined->isNotEmpty() ? $combined : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
