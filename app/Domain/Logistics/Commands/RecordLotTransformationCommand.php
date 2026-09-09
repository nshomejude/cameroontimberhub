<?php

namespace App\Domain\Logistics\Commands;

use App\Support\Bus\Command;

/**
 * Record a mass-balance transformation. Thin DTO mirroring the exact
 * parameter shape of App\Models\LotTransformation::recordFor() — it does not
 * duplicate any of that method's mass-balance math (total/loss/ratio
 * computed from the line items, not caller-supplied aggregates).
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
