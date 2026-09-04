<?php

namespace App\Filament\Exporter\Resources\InspectionRequests\Pages;

use App\Filament\Exporter\Resources\InspectionRequests\InspectionRequestResource;
use App\Models\Inspector;
use App\Models\TimberLot;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

/**
 * Handles submission of a company's self-service inspection request.
 *
 * Inspector matching (blueprint §26/§27): among eligible inspectors
 * (Inspector::scopeEligible() -- identity-verified, agreement-accepted,
 * active) whose coverage_regions include the lot's origin_region (falling
 * back to the requesting company's region if the lot has none) AND whose
 * inspection_categories include the requested inspection_type, the one
 * with the fewest currently-pending (unfinalised) inspections is assigned
 * for basic load-balancing. If no eligible inspector matches either
 * criterion, the Inspection is still created with inspector_id = null --
 * left visibly unassigned in the staff Inspections admin resource (blank
 * "Inspector" column) for manual assignment.
 */
class CreateInspectionRequest extends CreateRecord
{
    protected static string $resource = InspectionRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()?->companies()->first();

        abort_unless($company, 403);

        // Defense in depth beyond the form's own options() scoping: never
        // trust a posted timber_lot_id without re-checking company ownership.
        $lot = TimberLot::query()
            ->forCompany($company->getKey())
            ->find($data['timber_lot_id'] ?? null);

        if (! $lot) {
            throw ValidationException::withMessages([
                'timber_lot_id' => 'That timber lot does not belong to your company.',
            ]);
        }

        $region = $lot->origin_region ?: $company->region;

        $inspector = $this->matchInspector($region, $data['inspection_type']);

        $data['timber_lot_id'] = $lot->id;
        $data['inspector_id'] = $inspector?->id;

        return $data;
    }

    private function matchInspector(?string $region, string $inspectionType): ?Inspector
    {
        $query = Inspector::query()->eligible();

        if ($region) {
            $query->whereJsonContains('coverage_regions', $region);
        }

        $query->whereJsonContains('inspection_categories', $inspectionType);

        return $query
            ->withCount(['inspections as pending_inspections_count' => function ($q) {
                $q->whereNull('finalised_at');
            }])
            ->orderBy('pending_inspections_count')
            ->first();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
