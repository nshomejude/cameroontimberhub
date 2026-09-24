<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Models\Rfq;
use App\Models\User;
use App\Services\CompanyReviewService;
use App\Services\MessagingService;
use App\Services\ReorderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a thread, now also for the in-thread commerce actions
 * (see `ChatCommerceController` / `ChatOrderController`).
 *
 * `kind` is `Message::type`'s real value (see `App\Enums\MessageType`) — no
 * invented discriminator. Every structured/"card" message still renders
 * READ-ONLY via its existing `payload` snapshot; `actions` and `state` are
 * the two new surfaces a client uses to render buttons and live figures.
 *
 * `is_read` mirrors `MessagingService::isReadByCounterparty()`: for a message
 * this user sent, has the *other* side read it yet. For a message the other
 * side sent, "read" is meaningless from this user's perspective, so it is
 * always `true` there.
 *
 * `actions` — the server-computed control surface
 * -------------------------------------------------
 * For each message, the actions THIS viewer may take on THIS message RIGHT
 * NOW, derived from the EXACT SAME booleans the web Blade partials under
 * `resources/views/public/messages/types/*.blade.php` gate their buttons on
 * (read those files' `@if`s — this method is a line-for-line port, not a
 * re-derivation). The one genuine extraction is `ReorderService::
 * hasBeenQuoted()`, pulled out of `reorder-request.blade.php` into a shared
 * method so the two call sites cannot drift (see that method's docblock).
 * Every action is `[]` unless a REAL control exists on the corresponding web
 * card — nothing here is invented.
 *
 * The view is never the gate either way: every one of these actions re-enters
 * `ChatCommerceService`/`OrderLifecycleService`/`ReorderService`, which
 * re-check the same rule server-side regardless of what this array said.
 *
 * `state` — live figures the client renders without re-deriving them
 * -------------------------------------------------
 * Unlike `payload` (a point-in-time snapshot, frozen when the card was
 * posted), every value under `state` is read off the SAME live record the
 * `actions` computation above and the Blade partial for this kind already
 * read (`$message->related` — a Quote, Order, Rfq, QuoteCounterOffer, or
 * CompanyReview) — never re-derived from `payload`. A field is omitted
 * entirely when it cannot be cleanly sourced this way, rather than guessed
 * or backfilled from the snapshot.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isOwn = $this->isFrom($user);

        return [
            'id' => $this->id,
            'kind' => $this->type->value,
            'body' => $this->body,
            'payload' => $this->payload,
            'state' => $this->state(),
            'sender' => [
                'id' => $this->sender_user_id,
                'name' => $this->sender?->name,
                'company_name' => $this->senderCompany?->name,
                'is_own' => $isOwn,
            ],
            'is_read' => $isOwn ? app(MessagingService::class)->isReadByCounterparty($this->resource, $user) : true,
            'actions' => $user ? $this->actions($user) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /* ================================================================ actions */

    /** @return list<array<string, mixed>> */
    private function actions(User $user): array
    {
        /** @var Message $message */
        $message = $this->resource;

        $conversation = $message->conversation ?? $message->conversation()->first();

        if ($conversation === null) {
            return [];
        }

        // Same as ChatCommerceService::party(), without re-throwing 404 for a
        // non-participant: this resource is only ever built from a thread the
        // controller already resolved through MessagingService::find(), so
        // the viewer is a proven participant by the time we get here.
        $isBuyer = (int) $conversation->user_id === (int) $user->getKey();
        $convId = $conversation->getKey();

        return match ($message->type) {
            MessageType::Quotation => $this->quotationActions($message, $isBuyer, $convId),
            MessageType::CounterOffer => $this->counterOfferActions($message, $isBuyer, $convId),
            MessageType::ReorderRequest => $this->reorderActions($message, $conversation, $isBuyer, $convId),
            MessageType::PaymentRequest => $this->paymentRequestActions($message, $isBuyer, $convId),
            MessageType::OrderDelivered => $this->orderDeliveredActions($message, $isBuyer, $convId),
            MessageType::OrderDocuments => $this->orderDocumentsActions($message, $isBuyer, $convId),
            MessageType::ShipmentUpdate => $this->shipmentUpdateActions($message, $isBuyer, $convId),
            MessageType::TransactionCompleted => $this->transactionCompletedActions($message, $user, $isBuyer, $convId),
            default => [],
        };
    }

    /** Field-hint shorthands, used across several actions below. */
    private function field(string $name, string $label, string $type, bool $required = false, array $extra = []): array
    {
        return array_merge(['name' => $name, 'label' => $label, 'type' => $type, 'required' => $required], $extra);
    }

    /**
     * `quotation.blade.php`: `$actionable = $quote?->isActionable()`. Buyer
     * gets counter/decline/accept; supplier gets withdraw. Settled or expired
     * -> nothing to press, on both sides.
     *
     * @return list<array<string, mixed>>
     */
    private function quotationActions(Message $message, bool $isBuyer, int $convId): array
    {
        /** @var Quote|null $quote */
        $quote = $message->related;

        if ($quote === null || ! $quote->isActionable()) {
            return [];
        }

        if ($isBuyer) {
            return [
                [
                    'key' => 'counter',
                    'label' => 'Counter offer',
                    'method' => 'POST',
                    'path' => "conversations/{$convId}/quotes/{$quote->getKey()}/counter",
                    'fields' => [
                        $this->field('unit_price', 'Unit price', 'decimal', true),
                        $this->field('quantity', 'Quantity', 'decimal'),
                        $this->field('unit', 'Unit', 'text'),
                        $this->field('incoterm', 'Incoterm', 'text'),
                        $this->field('lead_time_days', 'Lead time (days)', 'number'),
                        $this->field('payment_terms', 'Payment terms', 'text', false, ['max_length' => 255]),
                        $this->field('note', 'Message', 'textarea', false, ['max_length' => 1000]),
                    ],
                ],
                [
                    'key' => 'decline',
                    'label' => 'Decline',
                    'method' => 'POST',
                    'path' => "conversations/{$convId}/quotes/{$quote->getKey()}/decline",
                    'confirm' => 'Decline this quotation?',
                    'tone' => 'danger',
                    'fields' => [$this->field('reason', 'Reason', 'textarea', true, ['max_length' => 500])],
                ],
                [
                    'key' => 'accept',
                    'label' => 'Accept quotation',
                    'method' => 'POST',
                    'path' => "conversations/{$convId}/quotes/{$quote->getKey()}/accept",
                    'confirm' => 'Accept this quotation? Every other quotation on this request will be declined and an order will be created. This cannot be undone.',
                ],
            ];
        }

        return [
            [
                'key' => 'withdraw',
                'label' => 'Withdraw quotation',
                'method' => 'POST',
                'path' => "conversations/{$convId}/quotes/{$quote->getKey()}/withdraw",
                'confirm' => 'Withdraw this quotation? The buyer will no longer be able to accept it.',
                'tone' => 'danger',
                'fields' => [$this->field('reason', 'Reason', 'textarea', false, ['max_length' => 500])],
            ],
        ];
    }

    /**
     * `counter-offer.blade.php`: `$canRespond = $offer->isPending() &&
     * $offer->awaitingParty() === $viewerParty`. "Counter again" re-enters the
     * same `counter` action on the underlying quote.
     *
     * @return list<array<string, mixed>>
     */
    private function counterOfferActions(Message $message, bool $isBuyer, int $convId): array
    {
        /** @var QuoteCounterOffer|null $offer */
        $offer = $message->related;

        if ($offer === null || ! $offer->isPending()) {
            return [];
        }

        $viewerParty = $isBuyer ? 'buyer' : 'supplier';

        if ($offer->awaitingParty() !== $viewerParty) {
            return [];
        }

        $counterFields = [
            $this->field('unit_price', 'Unit price', 'decimal', true),
            $this->field('quantity', 'Quantity', 'decimal'),
            $this->field('unit', 'Unit', 'text'),
            $this->field('incoterm', 'Incoterm', 'text'),
            $this->field('lead_time_days', 'Lead time (days)', 'number'),
            $this->field('payment_terms', 'Payment terms', 'text', false, ['max_length' => 255]),
            $this->field('note', 'Message', 'textarea', false, ['max_length' => 1000]),
        ];

        return [
            [
                'key' => 'decline',
                'label' => 'Decline',
                'method' => 'POST',
                'path' => "conversations/{$convId}/counter-offers/{$offer->getKey()}/respond",
                'confirm' => 'Decline this counter-offer?',
                'tone' => 'danger',
                'fields' => [$this->field('decision', 'Decision', 'select', true, ['options' => [['value' => 'decline', 'label' => 'Decline']], 'default' => 'decline'])],
            ],
            [
                'key' => 'counter',
                'label' => 'Counter again',
                'method' => 'POST',
                'path' => "conversations/{$convId}/quotes/{$offer->quote_id}/counter",
                'fields' => $counterFields,
            ],
            [
                'key' => 'accept',
                'label' => 'Accept offer',
                'method' => 'POST',
                'path' => "conversations/{$convId}/counter-offers/{$offer->getKey()}/respond",
                'confirm' => 'Accept these terms? A revised quotation will be issued and the current one replaced.',
                'fields' => [$this->field('decision', 'Decision', 'select', true, ['options' => [['value' => 'accept', 'label' => 'Accept']], 'default' => 'accept'])],
            ],
        ];
    }

    /**
     * `reorder-request.blade.php`: the supplier pricing form appears only
     * `! $isBuyer && $routed && ! $answered`.
     *
     * @return list<array<string, mixed>>
     */
    private function reorderActions(Message $message, Conversation $conversation, bool $isBuyer, int $convId): array
    {
        if ($isBuyer) {
            return [];
        }

        /** @var Rfq|null $rfq */
        $rfq = $message->related;

        if ($rfq === null) {
            return [];
        }

        $reorders = app(ReorderService::class);

        $routed = $reorders->isRoutedToSupplier($rfq, $conversation->company_id);
        $answered = $reorders->hasBeenQuoted($rfq, $conversation->company_id);

        if (! $routed || $answered) {
            return [];
        }

        return [[
            'key' => 'quote',
            'label' => 'Price this reorder',
            'method' => 'POST',
            'path' => "conversations/{$convId}/reorders/{$rfq->getKey()}/quote",
            'fields' => [
                $this->field('lines', 'Line prices', 'text', true, ['help' => 'array<order_item_id, {unit_price: number}>']),
                $this->field('lead_time_days', 'Lead time (days)', 'number'),
                $this->field('validity_days', 'Valid for (days)', 'number'),
                $this->field('payment_terms', 'Payment terms', 'text', false, ['max_length' => 255]),
                $this->field('shipping_amount', 'Shipping amount', 'decimal'),
            ],
        ]];
    }

    /**
     * `payment-request.blade.php`: `@if (! $isBuyer && $order && !
     * $order->status->isTerminal())`.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentRequestActions(Message $message, bool $isBuyer, int $convId): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($isBuyer || $order === null || $order->status->isTerminal()) {
            return [];
        }

        return [[
            'key' => 'record_payment',
            'label' => 'Record a payment received',
            'method' => 'POST',
            'path' => "conversations/{$convId}/orders/{$order->getKey()}/payment-record",
            'fields' => [
                $this->field('amount', 'Amount received', 'decimal', true),
                $this->field('method', 'How it arrived', 'text', false, ['placeholder' => 'e.g. Bank transfer', 'max_length' => 80]),
            ],
        ]];
    }

    /**
     * `order-delivered.blade.php`: `@if ($isBuyer && $order->status ===
     * OrderStatus::Delivered)`.
     *
     * @return list<array<string, mixed>>
     */
    private function orderDeliveredActions(Message $message, bool $isBuyer, int $convId): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if (! $isBuyer || $order === null || $order->status !== OrderStatus::Delivered) {
            return [];
        }

        return [[
            'key' => 'complete',
            'label' => 'Confirm receipt and close this order',
            'method' => 'POST',
            'path' => "conversations/{$convId}/orders/{$order->getKey()}/complete",
            'confirm' => 'Confirm receipt and close this order? Only you can close it. Once closed you can review the supplier.',
        ]];
    }

    /**
     * `order-documents.blade.php`: `@if (! $isBuyer)`.
     *
     * @return list<array<string, mixed>>
     */
    private function orderDocumentsActions(Message $message, bool $isBuyer, int $convId): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($isBuyer || $order === null) {
            return [];
        }

        return [[
            'key' => 'add_documents',
            'label' => 'Attach a document',
            'method' => 'POST',
            'path' => "conversations/{$convId}/orders/{$order->getKey()}/documents",
            'fields' => [
                $this->field('documents', 'Files', 'text', true, ['help' => 'file[] (multipart), PDF/JPG/PNG/WEBP up to 15MB each']),
                $this->field('kind', 'Type', 'select', false, ['options' => collect(\App\Enums\OrderDocumentKind::options())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()]),
                $this->field('label', 'Label', 'text', false, ['max_length' => 160]),
            ],
        ]];
    }

    /**
     * `shipment-update.blade.php`: `@if (! $isBuyer && !
     * $order->status->isTerminal())`, then per-status buttons; "Shipment
     * details" (tracking) is offered at every one of those statuses.
     *
     * @return list<array<string, mixed>>
     */
    private function shipmentUpdateActions(Message $message, bool $isBuyer, int $convId): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($isBuyer || $order === null || $order->status->isTerminal()) {
            return [];
        }

        $actions = [];
        $base = "conversations/{$convId}/orders/{$order->getKey()}";

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
                    $this->field('carrier', 'Carrier', 'text'),
                    $this->field('tracking_number', 'Tracking number', 'text'),
                    $this->field('tracking_url', 'Carrier tracking link', 'text', false, ['placeholder' => 'https://…']),
                ],
            ];
        }

        if ($order->status === OrderStatus::Shipped) {
            $actions[] = [
                'key' => 'deliver', 'label' => 'Mark as delivered', 'method' => 'POST', 'path' => "{$base}/deliver",
                'fields' => [
                    $this->field('received_by', 'Received by', 'text', false, ['max_length' => 160]),
                    $this->field('location', 'Delivery location', 'text', false, ['max_length' => 200]),
                    $this->field('proof', 'Proof of delivery', 'text', false, ['help' => 'file[] (multipart), up to 5']),
                ],
            ];
        }

        $actions[] = [
            'key' => 'tracking',
            'label' => 'Shipment details',
            'method' => 'POST',
            'path' => "{$base}/tracking",
            'fields' => [
                $this->field('carrier', 'Carrier', 'text'),
                $this->field('tracking_number', 'Tracking number', 'text'),
                $this->field('shipping_method', 'Shipping method', 'text'),
                $this->field('vessel_name', 'Vessel', 'text'),
                $this->field('voyage_number', 'Voyage', 'text'),
                $this->field('container_number', 'Container', 'text'),
                $this->field('port_of_loading', 'Port of loading', 'text'),
                $this->field('port_of_discharge', 'Port of discharge', 'text'),
                $this->field('etd', 'Departed', 'date'),
                $this->field('eta', 'Estimated arrival', 'date'),
                $this->field('tracking_url', 'Carrier tracking link', 'text'),
            ],
        ];

        return $actions;
    }

    /**
     * `transaction-completed.blade.php`: `$canReview = $isBuyer &&
     * CompanyReviewService::canReview($user, $order)`.
     *
     * @return list<array<string, mixed>>
     */
    private function transactionCompletedActions(Message $message, User $user, bool $isBuyer, int $convId): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if (! $isBuyer || $order === null || ! app(CompanyReviewService::class)->canReview($user, $order)) {
            return [];
        }

        return [[
            'key' => 'review',
            'label' => 'Leave a review',
            'method' => 'POST',
            'path' => "conversations/{$convId}/orders/{$order->getKey()}/review",
            'fields' => [
                $this->field('rating', 'Rating (1-5)', 'number', true),
                $this->field('title', 'Title', 'text', false, ['max_length' => 160]),
                $this->field('body', 'Review', 'textarea', false, ['max_length' => 2000]),
            ],
        ]];
    }

    /* ================================================================= state */

    /**
     * Live figures for this card, read off the same related record the
     * `actions` computation and the Blade partial for this kind read — never
     * off `payload`. A field is omitted, not guessed, when it cannot be
     * cleanly sourced.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        /** @var Message $message */
        $message = $this->resource;

        return match ($message->type) {
            MessageType::Quotation => $this->quotationState($message),
            MessageType::CounterOffer => $this->counterOfferState($message),
            MessageType::ReorderRequest => $this->reorderState($message),
            MessageType::RfqReference => $this->rfqState($message),
            MessageType::ContractAcceptance => $this->contractAcceptanceState($message),
            MessageType::PaymentRequest => $this->paymentRequestState($message),
            MessageType::PaymentConfirmed => $this->paymentConfirmedState($message),
            MessageType::ShipmentUpdate => $this->shipmentUpdateState($message),
            MessageType::OrderDelivered => $this->orderDeliveredState($message),
            MessageType::OrderDocuments => $this->orderDocumentsState($message),
            MessageType::OrderStatus, MessageType::OrderReference => $this->orderStatusState($message),
            MessageType::TransactionCompleted => $this->transactionCompletedState($message),
            MessageType::CompanyReview => $this->companyReviewState($message),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function lineItems(iterable $items): array
    {
        return collect($items)->map(fn ($item) => array_filter([
            'description' => $item->description ?? null,
            'species_name' => $item->species_name ?? null,
            'grade' => $item->grade ?? null,
            'dimensions' => $item->dimensions ?? null,
            'form' => is_object($item->form ?? null) ? $item->form->value : ($item->form ?? null),
            'quantity' => isset($item->quantity) ? (string) $item->quantity : null,
            'unit_label' => isset($item->unit) ? (is_object($item->unit) ? $item->unit->label() : $item->unit) : null,
            'unit_price' => isset($item->unit_price) ? (string) $item->unit_price : null,
            'line_total' => isset($item->line_total) ? (string) $item->line_total : null,
        ], fn ($v) => $v !== null))->all();
    }

    private function quotationState(Message $message): array
    {
        /** @var Quote|null $quote */
        $quote = $message->related;

        if ($quote === null) {
            return [];
        }

        $quote->loadMissing('items');

        return array_filter([
            'status' => $quote->status->value,
            'status_label' => $quote->threadStatusLabel(),
            'valid_until' => $quote->valid_until?->toIso8601String(),
            'items' => $this->lineItems($quote->items),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function counterOfferState(Message $message): array
    {
        /** @var QuoteCounterOffer|null $offer */
        $offer = $message->related;

        if ($offer === null) {
            return [];
        }

        return array_filter([
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
        ], fn ($v) => $v !== null);
    }

    private function reorderState(Message $message): array
    {
        /** @var Rfq|null $rfq */
        $rfq = $message->related;

        if ($rfq === null) {
            return [];
        }

        return array_filter([
            'status' => $rfq->status->value,
            'status_label' => $rfq->status->label(),
        ], fn ($v) => $v !== null);
    }

    private function rfqState(Message $message): array
    {
        /** @var Rfq|null $rfq */
        $rfq = $message->related;

        if ($rfq === null) {
            return [];
        }

        return array_filter([
            'status' => $rfq->status->value,
            'status_label' => $rfq->status->label(),
        ], fn ($v) => $v !== null);
    }

    private function contractAcceptanceState(Message $message): array
    {
        /** @var \App\Models\ContractAcceptance|null $acceptance */
        $acceptance = $message->related;

        $quote = $acceptance?->quote;

        if ($quote === null) {
            return [];
        }

        return array_filter([
            'status' => $quote->status->value,
            'status_label' => $quote->status->label(),
        ], fn ($v) => $v !== null);
    }

    private function paymentRequestState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        return array_filter([
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'amount_due' => $order->balanceDue(),
            'payment_due_at' => $order->payment_due_at?->toIso8601String(),
            'payment_method' => $order->payment_method,
            'instructions' => $order->company?->payment_instructions,
        ], fn ($v) => $v !== null);
    }

    private function paymentConfirmedState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        $receipt = $order->receipt;

        return array_filter([
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'receipt_number' => $receipt?->receipt_number,
            'verification_url' => $receipt?->verificationUrl(),
            'balance_due' => $order->balanceDue(),
        ], fn ($v) => $v !== null);
    }

    private function shipmentUpdateState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        return array_filter([
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'status_description' => $order->status->description(),
            'carrier' => $order->carrier,
            'tracking_number' => $order->tracking_number,
            'vessel' => $order->vessel_name,
            'eta' => $order->eta?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    private function orderDeliveredState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        return array_filter([
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'received_by' => $order->delivered_to_name,
            'documents' => $this->documentList($order),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function orderDocumentsState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        return array_filter([
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'documents' => $this->documentList($order),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function orderStatusState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        return [
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'milestones' => collect($order->milestones())->map(fn (array $m) => [
                'label' => $m['status']->label(),
                'reached' => $m['reached'],
                'at' => $m['at']?->toIso8601String(),
            ])->all(),
        ];
    }

    private function transactionCompletedState(Message $message): array
    {
        /** @var Order|null $order */
        $order = $message->related;

        if ($order === null) {
            return [];
        }

        $receipt = $order->receipt;

        return array_filter([
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'receipt_number' => $receipt?->receipt_number,
            'verification_url' => $receipt?->verificationUrl(),
            'balance_due' => $order->balanceDue(),
        ], fn ($v) => $v !== null);
    }

    private function companyReviewState(Message $message): array
    {
        /** @var \App\Models\CompanyReview|null $review */
        $review = $message->related instanceof \App\Models\CompanyReview ? $message->related : null;

        if ($review === null) {
            return [];
        }

        return array_filter([
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'author_name' => $review->authorDisplayName(),
        ], fn ($v) => $v !== null);
    }

    /**
     * Documents on this order, as the client-facing shape, with a
     * Bearer-token-reachable `/api/v1` download path (the conversation-scoped
     * route, not the signed web link — see `ChatOrderController::
     * downloadDocument()`'s docblock for why that route exists).
     *
     * @return list<array<string, mixed>>
     */
    private function documentList(Order $order): array
    {
        $conversationId = $order->conversation?->id ?? $order->conversation()->value('id');

        if ($conversationId === null) {
            return [];
        }

        return $order->documents->map(fn (OrderDocument $doc) => [
            'display_name' => $doc->displayName(),
            'kind' => $doc->kind?->value,
            'size' => $doc->humanSize(),
            'download_url' => "conversations/{$conversationId}/orders/{$order->getKey()}/documents/{$doc->getKey()}/download",
        ])->all();
    }
}