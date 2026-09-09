<?php

namespace App\Domain\Trade\Commands;

use App\Support\Bus\Command;

/**
 * Withdraw a quote (supplier-initiated — see QuoteService::withdraw() and
 * ChatCommerceService::withdrawQuotation(), which gates this to the supplier
 * side of the thread). Thin DTO; the reason is optional, mirroring
 * QuoteService::withdraw()'s signature.
 */
final class WithdrawQuoteCommand implements Command
{
    public function __construct(
        public readonly int $quoteId,
        public readonly ?string $reason = null,
        public readonly ?int $actingUserId = null,
    ) {}
}
