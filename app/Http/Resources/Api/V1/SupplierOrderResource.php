<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its SUPPLIER may see it — the API counterpart of the exporter
 * panel's Orders resource (`OrdersTable` shows `buyer_company`/`buyer_name`
 * per row today), reshaped for the mobile app.
 *
 * Deliberately NOT the same shape as the buyer-facing OrderResource:
 *
 *  - `OrderResource` carries `supplier_name` (the buyer is told who is
 *    fulfilling their order) but never carries buyer identity at all — a
 *    buyer has no reason to see their own name and email echoed back. A
 *    supplier, by contrast, needs the buyer's identity to fulfil and ship
 *    the order — exactly the fields the exporter Orders/Leads tables
 *    already show them (`buyer_name`, `buyer_company`, `buyer_email`,
 *    `buyer_country_code`), so this resource adds them in place of
 *    `supplier_name` (redundant here — it is the caller's own company).
 *  - Every money/status/logistics field OrderResource exposes is safe for a
 *    supplier too — it is THEIR OWN order, they set most of these fields via
 *    the quote they submitted — so this resource otherwise mirrors it
 *    field-for-field rather than inventing a second shape to maintain.
 *
 * @mixin Order
 */
class SupplierOrderResource extends JsonResource
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
            'buyer_name' => $this->buyer_name,
            'buyer_company' => $this->buyer_company,
            'buyer_email' => $this->buyer_email,
            'buyer_country_code' => $this->buyer_country_code,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'production_started_at' => $this->production_started_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->id),
            'actions' => $this->supplierActions(),
        ];
    }

    /**
     * The order-detail equivalent of `MessageResource`'s `actions[]` for the
     * supplier side. Same key/label/method/path shape and the exact same
     * per-status gates `MessageResource::shipmentUpdateActions()` /
     * `paymentRequestActions()` / `orderDocumentsActions()` already use for
     * the in-thread cards — this is a line-for-line reuse of those gates,
     * not a re-derivation:
     *
     *  - `confirm` only from `Awarded` (the only status
     *    `OrderService::TRANSITIONS` allows into `Confirmed`).
     *  - `production` (startProduction) only from `Confirmed`.
     *  - `ship` from `Confirmed` or `InProduction`, matching
     *    `OrderService::TRANSITIONS['confirmed'/'in_production']` both
     *    allowing `shipped`.
     *  - `deliver` only from `Shipped`.
     *  - `tracking` (updateTracking), `add_documents` (attachDocuments),
     *    `proforma`, `request_payment` and `record_payment` carry no extra
     *    status transition of their own in `OrderLifecycleService` — each is
     *    refused only once the order is terminal (`OrderStatus::isTerminal()`
     *    covers `Completed`/`Cancelled`), exactly the guard
     *    `shipmentUpdateActions()`/`paymentRequestActions()` already use.
     *
     * Every path is `conversations/{conversation_id}/orders/{order_id}/...`,
     * verbatim from the `conversations/{id}/orders/{order}` route group in
     * `routes/api.php` (`confirm`, `production`, `ship`, `tracking`,
     * `deliver`, `documents`, `proforma`, `payment-request`,
     * `payment-record`) — copied, not guessed. No conversation, no actions:
     * an order without a thread yet gets `actions: []`, not broken paths.
     *
     * @return list<array<string, mixed>>
     */
    private function supplierActions(): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $conversationId = $order->relationLoaded('conversation') ? $order->conversation?->id : null;

        if ($conversationId === null) {
            return [];
        }

        $base = "conversations/{$conversationId}/orders/{$order->getKey()}";
        $actions = [];

        if ($order->status === OrderStatus::Awarded) {
            $actions[] = ['key' => 'confirm', 'label' => 'Confirm order', 'method' => 'POST', 'path' => "{$base}/confirm"];
        }

        if ($order->status === OrderStatus::Confirmed) {
            $actions[] = ['key' => 'production', 'label' => 'Start production', 'method' => 'POST', 'path' => "{$base}/production"];
        }

        if (in_array($order->status, [OrderStatus::Confirmed, OrderStatus::InProduction], true)) {
            $actions[] = [
                'key' => 'ship', 'label' => 'Mark as shipped', 'method' => 'POST', 'path' => "{$base}/ship",
                'fields' => [
                    ['name' => 'carrier', 'label' => 'Carrier', 'type' => 'text', 'required' => false],
                    ['name' => 'tracking_number', 'label' => 'Tracking number', 'type' => 'text', 'required' => false],
                    ['name' => 'tracking_url', 'label' => 'Carrier tracking link', 'type' => 'text', 'required' => false, 'placeholder' => 'https://…'],
                ],
            ];
        }

        if ($order->status === OrderStatus::Shipped) {
            $actions[] = [
                'key' => 'deliver', 'label' => 'Mark as delivered', 'method' => 'POST', 'path' => "{$base}/deliver",
                'fields' => [
                    ['name' => 'received_by', 'label' => 'Received by', 'type' => 'text', 'required' => false, 'max_length' => 160],
                    ['name' => 'location', 'label' => 'Delivery location', 'type' => 'text', 'required' => false, 'max_length' => 200],
                    ['name' => 'proof', 'label' => 'Proof of delivery', 'type' => 'text', 'required' => false, 'help' => 'file[] (multipart), up to 5'],
                ],
            ];
        }

        if (! $order->status->isTerminal()) {
            $actions[] = [
                'key' => 'tracking',
                'label' => 'Shipment details',
                'method' => 'POST',
                'path' => "{$base}/tracking",
                'fields' => [
                    ['name' => 'carrier', 'label' => 'Carrier', 'type' => 'text', 'required' => false],
                    ['name' => 'tracking_number', 'label' => 'Tracking number', 'type' => 'text', 'required' => false],
                    ['name' => 'shipping_method', 'label' => 'Shipping method', 'type' => 'text', 'required' => false],
                    ['name' => 'vessel_name', 'label' => 'Vessel', 'type' => 'text', 'required' => false],
                    ['name' => 'voyage_number', 'label' => 'Voyage', 'type' => 'text', 'required' => false],
                    ['name' => 'container_number', 'label' => 'Container', 'type' => 'text', 'required' => false],
                    ['name' => 'port_of_loading', 'label' => 'Port of loading', 'type' => 'text', 'required' => false],
                    ['name' => 'port_of_discharge', 'label' => 'Port of discharge', 'type' => 'text', 'required' => false],
                    ['name' => 'etd', 'label' => 'Departed', 'type' => 'date', 'required' => false],
                    ['name' => 'eta', 'label' => 'Estimated arrival', 'type' => 'date', 'required' => false],
                    ['name' => 'tracking_url', 'label' => 'Carrier tracking link', 'type' => 'text', 'required' => false],
                ],
            ];

            $actions[] = [
                'key' => 'add_documents',
                'label' => 'Attach a document',
                'method' => 'POST',
                'path' => "{$base}/documents",
                'fields' => [
                    ['name' => 'documents', 'label' => 'Files', 'type' => 'text', 'required' => true, 'help' => 'file[] (multipart), PDF/JPG/PNG/WEBP up to 15MB each'],
                    ['name' => 'kind', 'label' => 'Type', 'type' => 'select', 'required' => false],
                    ['name' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => false, 'max_length' => 160],
                ],
            ];

            $actions[] = [
                'key' => 'proforma',
                'label' => 'Issue proforma invoice',
                'method' => 'POST',
                'path' => "{$base}/proforma",
            ];

            $actions[] = [
                'key' => 'request_payment',
                'label' => 'Request payment',
                'method' => 'POST',
                'path' => "{$base}/payment-request",
                'fields' => [
                    ['name' => 'due_date', 'label' => 'Due date', 'type' => 'date', 'required' => false],
                    ['name' => 'reference', 'label' => 'Reference', 'type' => 'text', 'required' => false, 'max_length' => 120],
                ],
            ];

            $actions[] = [
                'key' => 'record_payment',
                'label' => 'Record a payment received',
                'method' => 'POST',
                'path' => "{$base}/payment-record",
                'fields' => [
                    ['name' => 'amount', 'label' => 'Amount received', 'type' => 'decimal', 'required' => true],
                    ['name' => 'method', 'label' => 'How it arrived', 'type' => 'text', 'required' => false, 'placeholder' => 'e.g. Bank transfer', 'max_length' => 80],
                ],
            ];
        }

        return $actions;
    }
}
