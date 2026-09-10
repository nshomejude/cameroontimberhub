<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trade\Commands\AwardQuoteCommand;
use App\Domain\Trade\Commands\DeclineQuoteCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeclineQuoteRequest;
use App\Http\Resources\Api\V1\OrderSummaryResource;
use App\Http\Resources\Api\V1\QuoteResource;
use App\Services\BuyerApiScope;
use App\Services\QuoteService;
use App\Support\Bus\CommandBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Buyer decisions on quotes.
 *
 * Both decisions go through the CommandBus (AwardQuoteCommand /
 * DeclineQuoteCommand), whose handlers are thin seams over QuoteService — so
 * the API inherits the whole state machine unchanged: accept() locks the
 * rows, declines every sibling quote, closes the RFQ and mints the order
 * inside one transaction; decline() demands a reason and refuses illegal
 * moves. An already-settled or expired quote throws there and lands here as a
 * 409 — the guard is the service's, not a second copy of it, and the partial
 * unique index on accepted quotes backs it up in the database if two
 * requests ever race past PHP.
 */
class QuoteController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly QuoteService $quotes,
        private readonly CommandBus $commands,
    ) {}

    public function show(Request $request, string $reference): QuoteResource
    {
        $quote = $this->scope->quote($request->user(), $reference);
        $quote->load(['items', 'company', 'rfq']);

        // First buyer view flips submitted -> viewed. Idempotent, never an error.
        $this->quotes->markViewed($quote);

        return new QuoteResource($quote->refresh()->load(['items', 'company', 'rfq']));
    }

    public function accept(Request $request, string $reference): JsonResponse
    {
        $buyer = $request->user();
        $quote = $this->scope->quote($buyer, $reference);

        if (! $quote->isActionable()) {
            return $this->settled($quote->status->label());
        }

        try {
            $accepted = $this->commands->dispatch(new AwardQuoteCommand(
                quoteId: $quote->getKey(),
                actingUserId: $buyer?->getKey(),
            ));
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        $accepted->load(['items', 'company', 'rfq', 'order']);

        return response()->json([
            'data' => [
                'quote' => new QuoteResource($accepted),
                'order' => $accepted->order ? new OrderSummaryResource($accepted->order) : null,
            ],
        ]);
    }

    public function decline(DeclineQuoteRequest $request, string $reference): JsonResponse
    {
        $buyer = $request->user();
        $quote = $this->scope->quote($buyer, $reference);

        if (! $quote->isActionable()) {
            return $this->settled($quote->status->label());
        }

        try {
            $declined = $this->commands->dispatch(new DeclineQuoteCommand(
                quoteId: $quote->getKey(),
                reason: $request->validated()['reason'],
                actingUserId: $buyer?->getKey(),
            ));
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'data' => new QuoteResource($declined->load(['items', 'company', 'rfq'])),
        ]);
    }

    private function settled(string $status): JsonResponse
    {
        return $this->conflict("This quote is {$status} and can no longer be actioned.");
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_CONFLICT);
    }
}
