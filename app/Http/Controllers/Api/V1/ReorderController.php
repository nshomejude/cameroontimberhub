<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MessageResource;
use App\Services\BuyerApiScope;
use App\Services\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Buyer-initiated reorder over token auth — the API counterpart of
 * `Http\Controllers\Public\ReorderController::store()` (the web fallback for
 * the buyer side of `App\Livewire\Messaging\Thread`'s "Order this again").
 *
 * Buyer-only, on purpose: the supplier-side confirmation step
 * (`ReorderService::quote()`) has side effects that price a new contract and
 * is deliberately out of scope here, same as `ConversationController`'s
 * commerce actions — see that controller's docblock for the shape of this
 * rule elsewhere in the API.
 *
 * `{orderReference}` is resolved through `BuyerApiScope::order()`, the exact
 * same ownership boundary every other `orders/{orderReference}/...` route in
 * this group uses: another buyer's order 404s here too, never 403s.
 *
 * `ReorderService` already IS the authority on eligibility and idempotency —
 * this controller adds nothing to either rule, it only reshapes what the
 * service already decides into HTTP:
 *
 *  - `eligibility()` is a read, so it is always `200`. A GET that answers
 *    "can I?" is more useful to a client rendering a disabled/explained
 *    button than an exception it has to catch, so `canReorder()`/
 *    `assertEligible()`'s real refusal message rides along as
 *    `data.reason` instead of being thrown.
 *  - `store()` wraps `ReorderService::request()` verbatim. That method is
 *    itself idempotent for the ordinary case — a second call while a reorder
 *    is still open returns the SAME card rather than erroring (see its own
 *    "Fast path" comment) — so a double-tap or a replayed POST here answers
 *    `200` both times with an unchanged `message.id`, never a duplicate RFQ.
 *    Only the genuine race the service guards against with a DB unique
 *    index — two requests landing at once — surfaces as a `RuntimeException`
 *    containing "already open", which this controller is the one place that
 *    turns into `409 reorder_already_open`. Any other `RuntimeException`
 *    from `request()` (order not eligible after all, no line items, etc.) is
 *    the same real domain refusal `eligibility()` would have reported ahead
 *    of time, surfaced here as `422 reorder_not_eligible`.
 */
class ReorderController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly ReorderService $reorders,
    ) {}

    /**
     * Eligibility + prefill check. NOT a write — never mutates anything.
     *
     * `data.lines` is `ReorderService::previousLines()`'s real shape
     * (order-item id, species/form/grade/dimensions, quantity, unit, and the
     * previous unit price for REFERENCE ONLY — never a price the client can
     * post back), populated only when `eligible` is true. `data.in_progress`
     * plus `data.existing_rfq_reference` come from `openReorderFor()`, so the
     * client can render "you already have a reorder in progress" rather than
     * letting the buyer submit a second one that `store()` would just fold
     * into the existing card anyway.
     */
    public function eligibility(Request $request, string $orderReference): JsonResponse
    {
        $buyer = $request->user();
        $order = $this->scope->order($buyer, $orderReference);

        $eligible = $this->reorders->canReorder($buyer, $order);
        $reason = null;

        if (! $eligible) {
            try {
                $this->reorders->assertEligible($buyer, $order);
            } catch (RuntimeException $e) {
                // The real refusal message assertEligible() throws (e.g.
                // "This order was cancelled..." / "You can reorder once this
                // order has been delivered."), not an invented generic one.
                $reason = $e->getMessage();
            }
        }

        $existing = $this->reorders->openReorderFor($order);

        return response()->json([
            'data' => [
                'eligible' => $eligible,
                'reason' => $reason,
                'in_progress' => $existing !== null,
                'existing_rfq_reference' => $existing?->reference_code,
                'lines' => $eligible ? $this->reorders->previousLines($order) : null,
            ],
        ]);
    }

    /**
     * The write: wraps `ReorderService::request()`.
     *
     * Body mirrors the web `ReorderController::store()` validation exactly —
     * `quantities` (order item id => adjusted quantity), `shipping_port`,
     * `deadline`, `notes`. No price field exists because none is read: see
     * `ReorderService`'s class docblock, "Pricing — the crux".
     */
    public function store(Request $request, string $orderReference): JsonResponse
    {
        $data = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'shipping_port' => ['nullable', 'string', 'max:120'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $buyer = $request->user();
        $order = $this->scope->order($buyer, $orderReference);

        // Every order that has reached a reorder-eligible status was awarded
        // through the conversation path (ChatCommerceService::acceptQuotation()
        // stamps `conversations.order_id`), so this is the same thread the web
        // fallback resolves from its URL — just derived from the order instead
        // of carried in the route.
        $conversation = $order->conversation()->first();

        abort_unless($conversation !== null, 404);

        try {
            $message = $this->reorders->request($conversation, $order, $buyer, $data);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already open')) {
                throw new ConflictException($e->getMessage(), 'reorder_already_open', $e);
            }

            throw new ApiException(422, 'reorder_not_eligible', $e->getMessage(), previous: $e);
        }

        $message->loadMissing('sender', 'senderCompany');

        return response()->json([
            'data' => [
                'message' => new MessageResource($message),
                'conversation' => [
                    'id' => $conversation->getKey(),
                ],
            ],
        ], 201);
    }
}
