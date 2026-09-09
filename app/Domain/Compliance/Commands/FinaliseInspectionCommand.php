<?php

namespace App\Domain\Compliance\Commands;

use App\Support\Bus\Command;

/**
 * Finalise an inspection report (blueprint §27/§45-46). Thin DTO — carries
 * only the inspection identifier and the field data to fill in before
 * finalising (matching InspectorReportController::store()'s existing
 * "fill then finalise" flow); it does not duplicate any of Inspection's
 * digital-signature computation or immutability-guard logic.
 *
 * @param array<string, mixed> $data Validated report fields to fill onto the
 *   Inspection before finalising (may be empty when finalising a report
 *   whose fields were already saved).
 */
final class FinaliseInspectionCommand implements Command
{
    public function __construct(
        public readonly int $inspectionId,
        public readonly array $data = [],
    ) {}
}
