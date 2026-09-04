<?php

namespace App\Services;

use App\Models\ComplianceRule;
use App\Models\Inspection;
use App\Models\TimberLot;
use Illuminate\Support\Carbon;

/**
 * Assembles the "CTH Compliance Evidence Pack" (implementation blueprint
 * §83): a controlled, internal-only package for a single TimberLot —
 * supplier identity, applicable compliance checklist, origin information,
 * the traceability event ledger, processing records, and an inspection
 * report summary — with a generation timestamp.
 *
 * Disclosure restraint (blueprint §9, same rule the public Timber Passport
 * follows): supplier identity is limited to legal name + registration
 * number, never the company's private compliance documents themselves.
 * Origin location is the named region only — raw precise GPS coordinates
 * are never included, matching TimberPassportController's own pattern of
 * preferring origin_region and, only in its absence, degrading to a
 * coarse-rounded lat/lng rather than the full decimal:6 precision stored
 * on the model.
 *
 * This is assembly, not new business logic: every section reuses an
 * existing relation or scope (TimberLot::lotEvents(), input/output
 * transformations, ComplianceRule::applicableTo()) rather than
 * reimplementing a query that already exists elsewhere.
 */
class ComplianceEvidencePackService
{
    /**
     * @return array<string, mixed>
     */
    public function buildFor(TimberLot $lot, ?string $destinationCountryCode = null): array
    {
        $lot->loadMissing(['company', 'species', 'product']);

        return [
            'generated_at' => Carbon::now(),
            'lot' => $lot,
            'supplier' => $this->supplierIdentity($lot),
            'origin' => $this->originInformation($lot),
            'compliance_rules' => $this->applicableComplianceRules($lot, $destinationCountryCode),
            'lot_events' => $this->lotEvents($lot),
            'transformations' => $this->transformations($lot),
            'inspection' => $this->inspectionSummary($lot),
        ];
    }

    /**
     * Supplier identity only — legal name and registration number. Never
     * the company's private CompanyDocument records; those stay gated
     * behind DocumentDownloadController's own authorisation.
     *
     * @return array{legal_name: ?string, registration_number: ?string, country: ?string}|null
     */
    private function supplierIdentity(TimberLot $lot): ?array
    {
        $company = $lot->company;

        if (! $company) {
            return null;
        }

        return [
            'legal_name' => $company->legal_name,
            'registration_number' => $company->registration_number,
            'country' => $company->country_code,
        ];
    }

    /**
     * Origin region only. Falls back to a coarse-rounded (1 decimal place,
     * ~11km) coordinate pair when no named region is recorded, exactly as
     * the public Timber Passport does — never the raw decimal:6 value
     * stored on the model.
     *
     * @return array<string, mixed>
     */
    private function originInformation(TimberLot $lot): array
    {
        $coarseLocation = null;

        if (! $lot->origin_region && $lot->origin_latitude !== null && $lot->origin_longitude !== null) {
            $coarseLocation = number_format((float) $lot->origin_latitude, 1).', '.number_format((float) $lot->origin_longitude, 1);
        }

        return [
            'country' => $lot->origin_country,
            'region' => $lot->origin_region,
            'forest_source' => $lot->origin_forest_source,
            'harvest_block_reference' => $lot->harvest_block_reference,
            'approximate_location' => $coarseLocation,
            'harvest_period_start' => $lot->harvest_period_start,
            'harvest_period_end' => $lot->harvest_period_end,
        ];
    }

    /**
     * Applicable compliance checklist for a destination country, reusing
     * ComplianceRule::scopeApplicableTo() rather than reimplementing the
     * matching logic. Falls back to the lot's own origin_country when no
     * destination is supplied — still a real, defensible query rather than
     * an unfiltered dump of every rule in the system.
     *
     * @return \Illuminate\Support\Collection<int, ComplianceRule>
     */
    private function applicableComplianceRules(TimberLot $lot, ?string $destinationCountryCode): \Illuminate\Support\Collection
    {
        $countryCode = $destinationCountryCode ?: $lot->origin_country;

        if (! $countryCode) {
            return collect();
        }

        try {
            return ComplianceRule::query()
                ->applicableTo($countryCode)
                ->with('regulatorySource')
                ->orderBy('regulatory_framework')
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * The lot's Traceability Event Ledger (blueprint §10), oldest first.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\LotEvent>
     */
    private function lotEvents(TimberLot $lot): \Illuminate\Support\Collection
    {
        try {
            return $lot->lotEvents()->orderBy('id')->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Mass-balance / processing records (blueprint §11) where this lot
     * appears as either an input or an output.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\LotTransformation>
     */
    private function transformations(TimberLot $lot): \Illuminate\Support\Collection
    {
        try {
            $inputs = $lot->inputTransformations()->get();
            $outputs = $lot->outputTransformations()->get();

            return $inputs->merge($outputs)->unique('id')->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Summary of the most recent finalised inspection report linked to
     * this lot (Inspection belongsTo TimberLot; there is no inverse
     * relation on TimberLot itself, so this queries directly). Only
     * finalised reports are surfaced — an in-progress inspection has no
     * digital signature yet and is not evidence.
     *
     * @return array<string, mixed>|null
     */
    private function inspectionSummary(TimberLot $lot): ?array
    {
        $inspection = Inspection::query()
            ->where('timber_lot_id', $lot->getKey())
            ->whereNotNull('finalised_at')
            ->latest('finalised_at')
            ->first();

        if (! $inspection) {
            return null;
        }

        return [
            'inspection_type' => $inspection->inspection_type,
            'performed_at' => $inspection->performed_at,
            'finalised_at' => $inspection->finalised_at,
            'result' => $inspection->result,
            'observed_quantity' => $inspection->observed_quantity,
            'inspector_notes' => $inspection->inspector_notes,
            'digital_signature' => $inspection->digital_signature,
        ];
    }
}
