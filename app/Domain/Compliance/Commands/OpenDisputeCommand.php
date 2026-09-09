<?php

namespace App\Domain\Compliance\Commands;

use App\Enums\DisputeCategory;
use App\Support\Bus\Command;

/**
 * Open a formal dispute against an order (blueprint §64). Thin DTO — carries
 * only the identifiers/inputs the handler needs; it does not duplicate any
 * of DisputeService's party-resolution or validation rules.
 */
final class OpenDisputeCommand implements Command
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $actingUserId,
        public readonly DisputeCategory $category,
        public readonly string $description,
    ) {}
}
