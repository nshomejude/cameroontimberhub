<?php

namespace App\Http\Controllers\Public;

use App\Enums\TimberLotStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\TimberLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

/**
 * Public "CTH Timber Passport" page (implementation blueprint §9): a
 * disclosure-controlled public view of a single TimberLot. Only lots
 * belonging to a Company::scopePubliclyVisible() company, and not in the
 * Draft status, are publicly reachable — everything else 404s so we never
 * leak the existence of a draft/private lot via a different error shape.
 */
class TimberPassportController extends Controller
{
    /** timber_lots.legality_evidence_status check-constraint values. */
    public const LEGALITY_LABELS = [
        'not_assessed' => 'Not assessed',
        'incomplete' => 'Incomplete',
        'under_review' => 'Under review',
        'ready' => 'Ready',
        'remediation_required' => 'Remediation required',
        'expired' => 'Expired',
    ];

    /** timber_lots.traceability_status check-constraint values. */
    public const TRACEABILITY_LABELS = [
        'not_traceable' => 'Not traceable',
        'partial' => 'Partially traceable',
        'traceable' => 'Traceable',
        'fully_traceable' => 'Fully traceable',
    ];

    /** timber_lots.inspection_status check-constraint values. */
    public const INSPECTION_LABELS = [
        'not_inspected' => 'Not inspected',
        'scheduled' => 'Inspection scheduled',
        'passed' => 'Inspection passed',
        'failed' => 'Inspection failed',
        'conditional' => 'Conditionally passed',
    ];

    public function show(TimberLot $timberLot): View
    {
        $timberLot = TimberLot::query()
            ->whereKey($timberLot->getKey())
            ->where('status', '!=', TimberLotStatus::Draft->value)
            ->whereHas('company', fn (Builder $q) => $q->publiclyVisible())
            ->with(['company', 'species', 'product'])
            ->firstOrFail();

        $events = $this->loadTraceabilityEvents($timberLot);
        $transformations = $this->loadTransformationHistory($timberLot);

        return view('public.passport.show', [
            'lot' => $timberLot,
            'company' => $timberLot->company,
            'legalityLabel' => self::LEGALITY_LABELS[$timberLot->legality_evidence_status] ?? ucwords(str_replace('_', ' ', (string) $timberLot->legality_evidence_status)),
            'traceabilityLabel' => self::TRACEABILITY_LABELS[$timberLot->traceability_status] ?? ucwords(str_replace('_', ' ', (string) $timberLot->traceability_status)),
            'inspectionLabel' => self::INSPECTION_LABELS[$timberLot->inspection_status] ?? ucwords(str_replace('_', ' ', (string) $timberLot->inspection_status)),
            'events' => $events,
            'transformations' => $transformations,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'CTH Timber Passport', 'url' => route('passport.show', $timberLot)],
            ],
        ]);
    }

    /**
     * Defensively load traceability events for the concurrently-built
     * LotEvent model. Returns null (no section rendered) unless the class
     * exists AND we can actually load real event data for this lot.
     */
    private function loadTraceabilityEvents(TimberLot $timberLot): ?\Illuminate\Support\Collection
    {
        if (! class_exists(\App\Models\LotEvent::class)) {
            return null;
        }

        try {
            $events = $timberLot->lotEvents()->orderBy('id')->get();

            return $events->isNotEmpty() ? $events : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Defensively load mass-balance transformation history for the
     * concurrently-built LotTransformation model. Returns null (no section
     * rendered) unless the class exists AND we can actually load real data.
     */
    private function loadTransformationHistory(TimberLot $timberLot): ?\Illuminate\Support\Collection
    {
        if (! class_exists(\App\Models\LotTransformation::class)) {
            return null;
        }

        try {
            $inputs = $timberLot->inputTransformations()->get();
            $outputs = $timberLot->outputTransformations()->get();

            $combined = $inputs->merge($outputs)->unique('id');

            return $combined->isNotEmpty() ? $combined : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
