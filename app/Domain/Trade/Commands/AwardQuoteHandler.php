<?php

namespace App\Domain\Trade\Commands;

use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;

/**
 * Thin seam over the existing quote-awarding logic. All the actual state
 * machine, locking, sibling-decline and order-creation logic lives in
 * QuoteService::accept() (which itself calls OrderService::createFromQuote()
 * inside one DB transaction) — this handler is not a rewrite, it just gives
 * that behaviour a Command/Bus entry point.
 */
final class AwardQuoteHandler implements HandlesCommand
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function handle(Command $command): Quote
    {
        /** @var AwardQuoteCommand $command */
        $quote = Quote::findOrFail($command->quoteId);

        $actor = $command->actingUserId ? User::find($command->actingUserId) : null;

        return $this->quotes->accept($quote, $actor);
    }
}
