<?php

namespace App\Domain\Trade\Commands;

use App\Domain\Trade\Events\QuoteDeclined;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing quote-decline logic. All the actual state
 * machine and validation lives in QuoteService::decline() — this handler is
 * not a rewrite, it just gives that behaviour a Command/Bus entry point and
 * records the QuoteDeclined domain event to the outbox inside the same
 * transaction CommandBus::dispatch() already opens.
 */
final class DeclineQuoteHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function __construct(private readonly QuoteService $quotes) {}

    public function handle(Command $command): Quote
    {
        /** @var DeclineQuoteCommand $command */
        $quote = Quote::findOrFail($command->quoteId);

        $actor = $command->actingUserId ? User::find($command->actingUserId) : null;

        $declined = $this->quotes->decline($quote, $command->reason, $actor);

        $this->recordOutboxEvent(new QuoteDeclined(
            quoteId: $declined->getKey(),
            companyId: $declined->company_id,
            reason: $declined->decline_reason,
        ));

        return $declined;
    }
}
