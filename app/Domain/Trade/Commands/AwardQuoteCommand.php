<?php

namespace App\Domain\Trade\Commands;

use App\Support\Bus\Command;

/**
 * Award (accept) a quote. Thin DTO — carries only the identifiers the
 * handler needs to look up the real Eloquent models; it does not duplicate
 * any of QuoteService's business rules.
 */
final class AwardQuoteCommand implements Command
{
    public function __construct(
        public readonly int $quoteId,
        public readonly ?int $actingUserId = null,
    ) {}
}
