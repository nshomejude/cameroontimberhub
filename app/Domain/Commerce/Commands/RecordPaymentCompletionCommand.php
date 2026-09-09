<?php

namespace App\Domain\Commerce\Commands;

use App\Support\Bus\Command;

/**
 * Record that a Payment attempt completed (see App\Models\Payment::
 * markCompleted()). Thin DTO — carries only the identifiers the handler
 * needs to look up the real Eloquent model; it does not duplicate
 * markCompleted()'s logic.
 */
final class RecordPaymentCompletionCommand implements Command
{
    public function __construct(
        public readonly int $paymentId,
        public readonly ?string $providerReference = null,
    ) {}
}
