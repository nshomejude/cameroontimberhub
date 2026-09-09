<?php

namespace App\Domain\Trade\Commands;

use App\Support\Bus\Command;

/**
 * Decline a quote (buyer-initiated). Thin DTO — carries only the identifiers
 * and the mandatory reason the handler needs; it does not duplicate any of
 * QuoteService's business rules.
 */
final class DeclineQuoteCommand implements Command
{
    public function __construct(
        public readonly int $quoteId,
        public readonly string $reason,
        public readonly ?int $actingUserId = null,
    ) {}
}
