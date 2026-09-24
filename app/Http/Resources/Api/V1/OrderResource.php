<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use App\Services\CompanyReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single order's detail — the API counterpart to `/account/orders/{order}`
 * on the web (there is no dedicated web detail page yet; this is the same
 * data the account orders list already renders per-row, plus line items).
 *
 * Deliberately still narrow like OrderSummaryResource: no receipt
 * verification token, no internal payment notes, no cancellation reason
 * beyond what the buyer is meant to see. Items are only attached when the
 * caller eager-loaded them, exactly like QuoteResource does for its items.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status?->value,
            'currency' => $this->currency?->value,
            'subtotal_amount' => $this->subtotal_amount,
            'shipping_amount' => $this->shipping_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'incoterm' => $this->incoterm?->value,
            'payment_terms' => $this->payment_terms,
            'lead_time_days' => $this->lead_time_days,
            'shipping_port' => $this->shipping_port,
            'destination_country_code' => $this->destination_country_code,
            'expected_delivery_at' => $this->expected_delivery_at?->toDateString(),
            'etd' => $this->etd?->toDateString(),
            'eta' => $this->eta?->toDateString(),
            'supplier_name' => $this->supplier_name,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'production_started_at' => $this->production_started_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'has_trade_assurance' => $this->resource->relationLoaded('tradeAssuranceAgreement')
                ? $this->tradeAssuranceAgreement !== null
                : null,
            'can_open_dispute' => $this->resource->isDisputable(),
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->id),
            'actions' => $this->buyerActions($request),
        ];
    }

    /**
     * The order-detail equivalent of `MessageResource`'s `actions[]` — same
     * key/label/method/path shape, same real gates, just read off the order
     * directly instead of off a chat card:
     *
     *  - `complete` mirrors `MessageResource::orderDeliveredActions()`
     *    (`$isBuyer && status === Delivered`).
     *  - `review` mirrors `MessageResource::transactionCompletedActions()`,
     *    which defers to `CompanyReviewService::canReview()` rather than the
     *    weaker `Order::isReviewable()` — that service is the one place
     *    "already reviewed" is actually checked, so this reuses it instead
     *    of re-deriving eligibility from `isReviewable()` alone.
     *  - `open_dispute` reuses `Order::isDisputable()`, same as the existing
     *    `can_open_dispute` flag above. Its real endpoint
     *    (`Api\V1\DisputeController::store()`) is NOT one of
     *    `ChatOrderController`'s conversation-scoped routes and does not
     *    need a conversation to exist, so unlike `complete`/`review` it is
     *    not gated on `conversation_id` being present.
     *
     * `complete`/`review` need the real conversation id in their path (that
     * is how `ChatOrderController` routes are shaped), so when the order has
     * no conversation yet those two are simply omitted rather than emitting
     * a path that 404s.
     *
     * @return list<array<string, mixed>>
     */
    private function buyerActions(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $actions = [];

        if ($order->isDisputable()) {
            $actions[] = [
                'key' => 'open_dispute',
                'label' => 'Open a dispute',
                'method' => 'POST',
                'path' => "orders/{$order->reference_code}/disputes",
            ];
        }

        $conversationId = $order->relationLoaded('conversation') ? $order->conversation?->id : null;

        if ($conversationId === null) {
            return $actions;
        }

        $base = "conversations/{$conversationId}/orders/{$order->getKey()}";

        if ($order->status === \App\Enums\OrderStatus::Delivered) {
            $actions[] = [
                'key' => 'complete',
                'label' => 'Confirm receipt and close this order',
                'method' => 'POST',
                'path' => "{$base}/complete",
                'confirm' => 'Confirm receipt and close this order? Only you can close it. Once closed you can review the supplier.',
            ];
        }

        $user = $request->user();

        if ($user !== null && app(CompanyReviewService::class)->canReview($user, $order)) {
            $actions[] = [
                'key' => 'review',
                'label' => 'Leave a review',
                'method' => 'POST',
                'path' => "{$base}/review",
                'fields' => [
                    ['name' => 'rating', 'label' => 'Rating (1-5)', 'type' => 'number', 'required' => true],
                    ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => false, 'max_length' => 160],
                    ['name' => 'body', 'label' => 'Review', 'type' => 'textarea', 'required' => false, 'max_length' => 2000],
                ],
            ];
        }

        return $actions;
    }
}
