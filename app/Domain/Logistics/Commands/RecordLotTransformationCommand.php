<?php

namespace App\Domain\Logistics\Commands;

use App\Support\Bus\Command;

/**
 * Record a mass-balance transformation. Thin DTO mirroring the exact
 * parameter shape of App\Models\LotTransformation::recordFor() — it does not
 * duplicate any of that method's mass-balance math (total/loss/ratio
 * computed from the line items, not caller-supplied aggregates).
 *
 * Entry point: this Command is the API / programmatic path for recording a
 * transformation from its full input/output line items (see
 * tests/Feature/LogisticsCommandBusTest.php and any HTTP/CLI caller that has
 * the per-lot quantities). The Filament admin create flow
 * (App\Filament\Resources\LotTransformations) is deliberately a separate,
 * lightweight path: its form only captures the already-aggregated headline
 * figures (input/output/loss volumes) for manual ledger correction and never
 * has the per-lot line items recordFor() needs, so it cannot map onto this
 * Command's signature and does not dispatch it.
 */
final class RecordLotTransformationCommand implements Command
{
    /**
     * @param  array<int, array{lot: \App\Models\TimberLot|int, quantity: float|string}>  $inputs
     * @param  array<int, array{lot: \App\Models\TimberLot|int, quantity: float|string}>  $outputs
     */
    public function __construct(
        public readonly int $processorCompanyId,
        public readonly string $transformationType,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly ?\DateTimeInterface $processedAt = null,
        public readonly ?string $notes = null,
    ) {}
}
