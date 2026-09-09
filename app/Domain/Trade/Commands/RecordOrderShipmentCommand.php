<?php

namespace App\Domain\Trade\Commands;

use App\Support\Bus\Command;

/**
 * Mark an awarded/confirmed/in-production order as shipped. Thin DTO —
 * carries only the identifiers the handler needs to look up the real
 * Eloquent models; it does not duplicate any of OrderService's state-machine
 * rules (see OrderService::TRANSITIONS / ::ship()).
 */
final class RecordOrderShipmentCommand implements Command
{
    public function __construct(
        public readonly int $orderId,
        public readonly ?int $actingUserId = null,
    ) {}
}
