<?php

namespace App\Exceptions\Api;

use Throwable;

/**
 * A 409 "legal, but not now" — a quote already settled/expired, an illegal
 * state-machine move, a milestone already confirmed. Replaces the
 * `response()->json(['message' => $e->getMessage()], 409)` pattern that was
 * copied across QuoteController / DisputeController / TradeAssuranceController.
 *
 * `code` defaults to the generic `conflict`; a caller that knows the specific
 * case can pass a narrower stable code (e.g. `quote_not_actionable`).
 */
class ConflictException extends ApiException
{
    public function __construct(string $message, string $code = 'conflict', ?Throwable $previous = null)
    {
        parent::__construct(409, $code, $message, null, $previous);
    }
}
