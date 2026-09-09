<?php

namespace App\Domain\Trade\Commands;

use App\Domain\Trade\Events\QuoteWithdrawn;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing quote-withdraw logic (supplier-initiated — see
 * QuoteService::withdraw() and ChatCommerceService::withdrawQuotation(),
 * which is the only caller in production code today). This handler is not a
 * rewrite; it just gives that behaviour a Command/Bus entry point and
 * records the QuoteWithdrawn domain event to the outbox inside the same
 * transaction CommandBus::dispatch() already opens.
 */
final class WithdrawQuoteHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function __construct(private readonly QuoteService $quotes) {}

    public function handle(Command $command): Quote
    {
        /** @var WithdrawQuoteCommand $command */
        $quote = Quote::findOrFail($command->quoteId);

        $actor = $command->actingUserId ? User::find($command->actingUserId) : null;

        $withdrawn = $this->quotes->withdraw($quote, $actor, $command->reason);

        $this->recordOutboxEvent(new QuoteWithdrawn(
            quoteId: $withdrawn->getKey(),
            companyId: $withdrawn->company_id,
            reason: $command->reason,
        ));

        return $withdrawn;
    }
}
