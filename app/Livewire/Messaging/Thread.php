<?php

namespace App\Livewire\Messaging;

use App\Enums\RfqIncoterm;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Models\Rfq;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;
use App\Services\ReorderService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One conversation (mockup: "Mobile message thread").
 *
 * Near-real-time is `wire:poll.10s` on the message list — a 10 second beat is
 * fast enough that a live negotiation does not feel dead, and cheap enough for
 * a Postgres-backed app with no websocket layer (no Pusher/Reverb/Echo here by
 * requirement). The poll re-renders only this component.
 *
 * Not rendered, because none of it is real data: presence ("Online"), typing
 * indicators, and voice notes. Read state IS real — it is derived from the
 * counterparty's `last_read_at` — so the double-tick in the mockup is honest
 * and is kept.
 */
class Thread extends Component
{
    public int $conversationId;

    /** Where the back arrow goes on this surface. */
    public string $backUrl = '';

    /**
     * Optional plain-HTTP POST target for the composer, so it degrades to a
     * native form submit when JavaScript is off. Only the buyer surface has
     * such a route; the exporter panel is Filament, so it is empty there.
     */
    public string $postAction = '';

    public string $body = '';

    public ?int $replyToId = null;

    /** Id of the message whose action sheet is open (null = none). */
    public ?int $openActionsFor = null;

    public function mount(int $conversationId, string $backUrl = '', string $postAction = ''): void
    {
        // Resolves through the participant scope, so a foreign id 404s here
        // rather than anywhere further in.
        $conversation = app(MessagingService::class)->find(auth()->user(), $conversationId);

        $this->conversationId = (int) $conversation->getKey();
        $this->backUrl = $backUrl;
        $this->postAction = $postAction;
    }

    public function conversation(): Conversation
    {
        return app(MessagingService::class)->find(auth()->user(), $this->conversationId);
    }

    public function send(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $user = auth()->user();

        // Livewire posts do not pass through route middleware, so the same
        // limiter the HTTP fallback route uses is enforced here explicitly.
        $key = 'msg-send:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('body', 'You are sending messages too quickly. Try again in a moment.');

            return;
        }

        RateLimiter::hit($key, 60);

        $messaging = app(MessagingService::class);
        $conversation = $messaging->find($user, $this->conversationId);

        $messaging->post(
            $conversation,
            $user,
            $this->body,
            $this->replyToId ? Message::find($this->replyToId) : null,
        );

        $this->reset('body', 'replyToId', 'openActionsFor');
    }

    /* ------------------------------------------------------ chat commerce */

    /**
     * The in-thread commerce actions.
     *
     * Every one of them re-resolves the conversation through MessagingService
     * (so a tampered `conversationId` 404s), re-checks the per-account limiter
     * (Livewire posts bypass route middleware), and then hands off to
     * ChatCommerceService, which owns the buyer/supplier rule. Nothing about
     * who may do what is decided in this class or in the view.
     */

    /** Whether the inline RFQ composer card is open. */
    public bool $showRfqForm = false;

    /** Quote id whose counter-offer form is open, if any. */
    public ?int $counteringQuoteId = null;

    /** @var array<string, mixed> */
    public array $rfqForm = [
        'species_text' => '',
        'form' => '',
        'grade' => '',
        'dimensions' => '',
        'moisture_content' => '',
        'quantity' => '',
        'unit' => 'm3',
        'incoterm' => '',
        'shipping_port' => '',
        'deadline' => '',
        'notes' => '',
    ];

    /** @var array<string, mixed> */
    public array $counterForm = [
        'unit_price' => '',
        'quantity' => '',
        'payment_terms' => '',
        'lead_time_days' => '',
        'note' => '',
    ];

    public function toggleRfqForm(): void
    {
        $this->showRfqForm = ! $this->showRfqForm;
        $this->resetErrorBag();
    }

    public function submitRfq(): void
    {
        $this->validate([
            'rfqForm.species_text' => ['required', 'string', 'max:180'],
            'rfqForm.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'rfqForm.unit' => ['required', 'string', 'in:'.implode(',', RfqUnit::values())],
            'rfqForm.form' => ['nullable', 'string', 'in:'.implode(',', array_column(TimberForm::cases(), 'value'))],
            'rfqForm.incoterm' => ['nullable', 'string', 'in:'.implode(',', array_column(RfqIncoterm::cases(), 'value'))],
            'rfqForm.grade' => ['nullable', 'string', 'max:60'],
            'rfqForm.dimensions' => ['nullable', 'string', 'max:255'],
            'rfqForm.moisture_content' => ['nullable', 'string', 'max:60'],
            'rfqForm.shipping_port' => ['nullable', 'string', 'max:120'],
            'rfqForm.deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'rfqForm.notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'rfqForm.species_text' => 'species',
            'rfqForm.quantity' => 'quantity',
            'rfqForm.unit' => 'unit',
        ]);

        if (! $this->allow('chat-rfq', 12, 3600, 'rfqForm.species_text')) {
            return;
        }

        $this->commerce(function (ChatCommerceService $commerce, $conversation, $user) {
            $commerce->createRfqFromChat($conversation, $user, array_filter(
                $this->rfqForm,
                fn ($value) => $value !== '' && $value !== null,
            ));

            $this->reset('rfqForm', 'showRfqForm');
        }, 'rfqForm.species_text');
    }

    public function acceptQuote(int $quoteId): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(fn (ChatCommerceService $commerce, $conversation, $user) => $commerce->acceptQuotation(
            $conversation,
            $this->threadQuote($quoteId),
            $user,
            request(),
        ));
    }

    public function declineQuote(int $quoteId, string $reason = 'Declined from the conversation.'): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(fn (ChatCommerceService $commerce, $conversation, $user) => $commerce->declineQuotation(
            $conversation,
            $this->threadQuote($quoteId),
            $user,
            $reason,
        ));
    }

    public function withdrawQuote(int $quoteId): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(fn (ChatCommerceService $commerce, $conversation, $user) => $commerce->withdrawQuotation(
            $conversation,
            $this->threadQuote($quoteId),
            $user,
        ));
    }

    public function openCounter(int $quoteId): void
    {
        $this->counteringQuoteId = $quoteId;
        $this->reset('counterForm');
        $this->resetErrorBag();
    }

    public function cancelCounter(): void
    {
        $this->counteringQuoteId = null;
    }

    public function submitCounter(): void
    {
        $this->validate([
            'counterForm.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'counterForm.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'counterForm.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'counterForm.payment_terms' => ['nullable', 'string', 'max:255'],
            'counterForm.note' => ['nullable', 'string', 'max:1000'],
        ], [], ['counterForm.unit_price' => 'unit price']);

        if (! $this->allow('chat-decision', 10, 60, 'counterForm.unit_price')) {
            return;
        }

        $quoteId = (int) $this->counteringQuoteId;

        $this->commerce(function (ChatCommerceService $commerce, $conversation, $user) use ($quoteId) {
            $commerce->counter($conversation, $this->threadQuote($quoteId), $user, array_filter(
                $this->counterForm,
                fn ($value) => $value !== '' && $value !== null,
            ));

            $this->reset('counterForm', 'counteringQuoteId');
        }, 'counterForm.unit_price');
    }

    public function respondToCounter(int $offerId, string $decision): void
    {
        if (! $this->allow('chat-decision', 10, 60)) {
            return;
        }

        $this->commerce(function (ChatCommerceService $commerce, $conversation, $user) use ($offerId, $decision) {
            // Scoped lookup: an offer id from another thread never resolves.
            $offer = QuoteCounterOffer::where('conversation_id', $conversation->getKey())
                ->whereKey($offerId)
                ->firstOrFail();

            $commerce->respondToCounter($offer->setRelation('conversation', $conversation), $user, $decision);
        });
    }

    /* ------------------------------------------------ Phase 3: order lifecycle */

    /**
     * The in-thread order lifecycle actions.
     *
     * Exactly the same discipline as the quotation actions above: the
     * conversation is re-resolved through MessagingService on every call (so a
     * tampered `conversationId` 404s), the order is re-resolved through
     * OrderLifecycleService::threadOrder() (so an order id from another thread
     * 404s), the limiter is re-checked because Livewire bypasses route
     * middleware, and the buyer/supplier rule is decided *only* by
     * OrderLifecycleService.
     *
     * Nothing in this class or the views it renders decides who may act. The
     * buttons are hidden from the wrong side as a courtesy; the service refuses
     * them as the actual control.
     */

    /** Which order's tracking / payment / review form is open, if any. */
    public ?int $trackingForOrderId = null;

    public ?int $paymentForOrderId = null;

    public ?int $reviewForOrderId = null;

    /** @var array<string, mixed> */
    public array $trackingForm = [
        'carrier' => '',
        'tracking_number' => '',
        'tracking_url' => '',
        'shipping_method' => '',
        'vessel_name' => '',
        'voyage_number' => '',
        'container_number' => '',
        'port_of_loading' => '',
        'port_of_discharge' => '',
        'etd' => '',
        'eta' => '',
    ];

    /**
     * Amount and a free-text method name. There is deliberately no field here
     * for a card number, a bank account or any other credential — this records
     * a payment that already happened elsewhere and the platform must never be
     * the thing holding those details.
     *
     * @var array<string, mixed>
     */
    public array $paymentForm = [
        'amount' => '',
        'method' => '',
    ];

    /** @var array<string, mixed> */
    public array $reviewForm = [
        'rating' => '',
        'title' => '',
        'body' => '',
    ];

    public function issueProforma(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->issueProformaInvoice($conv, $order, $user));
    }

    public function sendPaymentRequest(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->requestPayment($conv, $order, $user));
    }

    public function confirmOrder(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->confirm($conv, $order, $user));
    }

    public function startProduction(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->startProduction($conv, $order, $user));
    }

    public function shipOrder(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->ship($conv, $order, $user));
    }

    public function deliverOrder(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->deliver($conv, $order, $user));
    }

    /** Buyer only — the supplier cannot close their own transaction. */
    public function completeOrder(int $orderId): void
    {
        $this->lifecycleAction($orderId, fn ($svc, $conv, $order, $user) => $svc->complete($conv, $order, $user));
    }

    /* ------------------------------------------------------- tracking form */

    public function openTracking(int $orderId): void
    {
        $this->trackingForOrderId = $orderId;
        $this->reset('trackingForm');
        $this->resetErrorBag();
    }

    public function cancelTracking(): void
    {
        $this->trackingForOrderId = null;
    }

    public function saveTracking(): void
    {
        $this->validate([
            'trackingForm.carrier' => ['nullable', 'string', 'max:120'],
            'trackingForm.tracking_number' => ['nullable', 'string', 'max:120'],
            'trackingForm.tracking_url' => ['nullable', 'url:http,https', 'max:500'],
            'trackingForm.shipping_method' => ['nullable', 'string', 'max:120'],
            'trackingForm.vessel_name' => ['nullable', 'string', 'max:120'],
            'trackingForm.voyage_number' => ['nullable', 'string', 'max:60'],
            'trackingForm.container_number' => ['nullable', 'string', 'max:60'],
            'trackingForm.port_of_loading' => ['nullable', 'string', 'max:120'],
            'trackingForm.port_of_discharge' => ['nullable', 'string', 'max:120'],
            'trackingForm.etd' => ['nullable', 'date'],
            'trackingForm.eta' => ['nullable', 'date'],
        ], [], ['trackingForm.tracking_url' => 'tracking link']);

        $orderId = (int) $this->trackingForOrderId;

        $this->lifecycleAction($orderId, function ($svc, $conv, $order, $user) {
            $svc->updateTracking($conv, $order, $user, $this->trackingForm);

            $this->reset('trackingForm', 'trackingForOrderId');
        }, 'trackingForm.carrier');
    }

    /* -------------------------------------------------------- payment form */

    public function openPayment(int $orderId): void
    {
        $this->paymentForOrderId = $orderId;
        $this->reset('paymentForm');
        $this->resetErrorBag();
    }

    public function cancelPayment(): void
    {
        $this->paymentForOrderId = null;
    }

    public function savePayment(): void
    {
        $this->validate([
            'paymentForm.amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'paymentForm.method' => ['nullable', 'string', 'max:80'],
        ], [], ['paymentForm.amount' => 'amount received']);

        $orderId = (int) $this->paymentForOrderId;

        $this->lifecycleAction($orderId, function ($svc, $conv, $order, $user) {
            $svc->recordPayment($conv, $order, $user, $this->paymentForm['amount'], $this->paymentForm['method'] ?: null);

            $this->reset('paymentForm', 'paymentForOrderId');
        }, 'paymentForm.amount');
    }

    /* --------------------------------------------------------- review form */

    public function openReview(int $orderId): void
    {
        $this->reviewForOrderId = $orderId;
        $this->reset('reviewForm');
        $this->resetErrorBag();
    }

    public function cancelReview(): void
    {
        $this->reviewForOrderId = null;
    }

    public function submitReview(): void
    {
        $this->validate([
            'reviewForm.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviewForm.title' => ['nullable', 'string', 'max:160'],
            'reviewForm.body' => ['nullable', 'string', 'max:2000'],
        ], [], ['reviewForm.rating' => 'rating']);

        $orderId = (int) $this->reviewForOrderId;

        $this->lifecycleAction($orderId, function ($svc, $conv, $order, $user) {
            $svc->review($conv, $order, $user, [
                'rating' => (int) $this->reviewForm['rating'],
                'title' => $this->reviewForm['title'] ?: null,
                'body' => $this->reviewForm['body'] ?: null,
            ]);

            $this->reset('reviewForm', 'reviewForOrderId');
        }, 'reviewForm.rating');
    }

    /* ------------------------------------------------------ Phase 4: reorder */

    /**
     * Reorder, from both sides.
     *
     * Buyer: `openReorder()` / `submitReorder()` — asks the supplier to repeat
     * a delivered or completed order. The form collects QUANTITIES and text
     * only. There is deliberately no price field on the buyer's side of this
     * component, and ReorderService reads none, so nothing a buyer types can
     * become the price of the new order.
     *
     * Supplier: `openReorderQuote()` / `submitReorderQuote()` — prices the
     * request. The unit price inputs live here and only here, and are required.
     */

    /** Source order id whose reorder form is open, if any. */
    public ?int $reorderForOrderId = null;

    /** Reorder RFQ id whose supplier pricing form is open, if any. */
    public ?int $quotingReorderRfqId = null;

    /** @var array<string, mixed> */
    public array $reorderForm = [
        'quantities' => [],
        'shipping_port' => '',
        'deadline' => '',
        'notes' => '',
    ];

    /** @var array<string, mixed> */
    public array $reorderQuoteForm = [
        'lines' => [],
        'lead_time_days' => '',
        'validity_days' => '',
        'payment_terms' => '',
        'shipping_amount' => '',
    ];

    public function openReorder(int $orderId): void
    {
        $user = auth()->user();
        $conversation = app(MessagingService::class)->find($user, $this->conversationId);
        $order = app(OrderLifecycleService::class)->threadOrder($conversation, $orderId);

        $this->reset('reorderForm');
        $this->resetErrorBag();

        // Pre-filled with what was ordered last time, which is a fact about the
        // buyer's own past order and is theirs to change. No price is
        // pre-filled anywhere, because there is no price field to fill.
        $this->reorderForm['quantities'] = $order->items
            ->mapWithKeys(fn ($item) => [(string) $item->getKey() => rtrim(rtrim((string) $item->quantity, '0'), '.')])
            ->all();

        $this->reorderForOrderId = (int) $order->getKey();
    }

    public function cancelReorder(): void
    {
        $this->reorderForOrderId = null;
    }

    public function submitReorder(): void
    {
        $this->validate([
            'reorderForm.quantities' => ['required', 'array', 'min:1'],
            'reorderForm.quantities.*' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reorderForm.shipping_port' => ['nullable', 'string', 'max:120'],
            'reorderForm.deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'reorderForm.notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['reorderForm.quantities.*' => 'quantity']);

        if (! $this->allow('chat-reorder', 12, 3600, 'reorderForm.quantities')) {
            return;
        }

        $orderId = (int) $this->reorderForOrderId;

        $this->lifecycleAction($orderId, function ($svc, $conv, $order, $user) {
            app(ReorderService::class)->request($conv, $order, $user, [
                'quantities' => $this->reorderForm['quantities'],
                'shipping_port' => $this->reorderForm['shipping_port'] ?: null,
                'deadline' => $this->reorderForm['deadline'] ?: null,
                'notes' => $this->reorderForm['notes'] ?: null,
            ]);

            $this->reset('reorderForm', 'reorderForOrderId');
        }, 'reorderForm.quantities');
    }

    /* ------------------------------------------- supplier prices the reorder */

    public function openReorderQuote(int $rfqId): void
    {
        $rfq = $this->threadReorderRfq($rfqId);

        $this->reset('reorderQuoteForm');
        $this->resetErrorBag();

        // Every unit price starts EMPTY. Carrying last time's figure in here as
        // a default would make "confirm" a single click on a price the supplier
        // never actually re-stated, which is the whole thing this phase must
        // not do. The previous price is shown as reference text on the card.
        $this->reorderQuoteForm['lines'] = $rfq->items
            ->mapWithKeys(fn ($item) => [(string) $item->getKey() => ['unit_price' => '']])
            ->all();

        $this->quotingReorderRfqId = (int) $rfq->getKey();
    }

    public function cancelReorderQuote(): void
    {
        $this->quotingReorderRfqId = null;
    }

    public function submitReorderQuote(): void
    {
        $this->validate([
            'reorderQuoteForm.lines' => ['required', 'array', 'min:1'],
            'reorderQuoteForm.lines.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reorderQuoteForm.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'reorderQuoteForm.validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'reorderQuoteForm.payment_terms' => ['nullable', 'string', 'max:255'],
            'reorderQuoteForm.shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ], [], ['reorderQuoteForm.lines.*.unit_price' => 'unit price']);

        if (! $this->allow('chat-decision', 10, 60, 'reorderQuoteForm.lines')) {
            return;
        }

        $rfqId = (int) $this->quotingReorderRfqId;
        $user = auth()->user();
        $conversation = app(MessagingService::class)->find($user, $this->conversationId);
        $rfq = $this->threadReorderRfq($rfqId);

        try {
            app(ReorderService::class)->quote($conversation, $rfq, $user, $this->reorderQuoteForm);

            $this->reset('reorderQuoteForm', 'quotingReorderRfqId');
        } catch (HttpExceptionInterface|RecordsNotFoundException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError('reorderQuoteForm.lines', $e->getMessage());
        }
    }

    /**
     * A reorder RFQ id, resolved only if it repeats an order that belongs to
     * both sides of this thread. Never a bare Rfq::find().
     */
    private function threadReorderRfq(int $rfqId): Rfq
    {
        $conversation = app(MessagingService::class)->find(auth()->user(), $this->conversationId);

        return Rfq::query()
            ->whereNotNull('reorder_of_order_id')
            ->whereHas('reorderOfOrder', fn ($q) => $q
                ->where('company_id', $conversation->company_id)
                ->where('user_id', $conversation->user_id))
            ->with('items')
            ->whereKey($rfqId)
            ->firstOr(fn () => abort(404));
    }

    /**
     * Shared plumbing for every lifecycle action.
     *
     * A RuntimeException here is a domain refusal — "you cannot ship an order
     * that was never confirmed", "you have already reviewed this order" — which
     * is the expected outcome of clicking a button that went stale while it was
     * on screen, so it becomes an inline error. Authorisation failures are
     * HttpExceptions and are re-thrown so a 403 stays a 403.
     */
    private function lifecycleAction(int $orderId, callable $action, string $errorField = 'body'): void
    {
        if (! $this->allow('order-lifecycle', 20, 60, $errorField)) {
            return;
        }

        $user = auth()->user();
        $conversation = app(MessagingService::class)->find($user, $this->conversationId);
        $lifecycle = app(OrderLifecycleService::class);

        // Scoped: an order that is not this thread's 404s here, so an id from
        // another buyer's conversation is indistinguishable from a missing one.
        $order = $lifecycle->threadOrder($conversation, $orderId);

        try {
            $action($lifecycle, $conversation, $order, $user);
        } catch (HttpExceptionInterface|RecordsNotFoundException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError($errorField, $e->getMessage());
        }
    }

    /* --------------------------------------------------- commerce plumbing */

    /**
     * Run a commerce action against the freshly re-resolved conversation.
     *
     * A RuntimeException is a domain refusal ("expired", "already accepted") —
     * the expected outcome of acting on a card that went stale while it was on
     * screen — so it becomes an inline error rather than a 500. Authorisation
     * failures are HttpExceptions and are left to propagate as 403/404.
     */
    private function commerce(callable $action, string $errorField = 'body'): void
    {
        $user = auth()->user();
        $conversation = app(MessagingService::class)->find($user, $this->conversationId);

        try {
            $action(app(ChatCommerceService::class), $conversation, $user);
        } catch (HttpExceptionInterface|RecordsNotFoundException $e) {
            // Symfony's HttpException extends RuntimeException. Without this
            // first catch, a 403 from the role gate would be demoted to an
            // inline validation message and the action would look merely
            // "rejected" rather than forbidden.
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError($errorField, $e->getMessage());
        }
    }

    /**
     * A quote id, resolved only if it belongs to the company on this thread.
     * Never a bare Quote::find().
     */
    private function threadQuote(int $quoteId): Quote
    {
        $conversation = app(MessagingService::class)->find(auth()->user(), $this->conversationId);

        // abort(404) rather than firstOrFail(): a quote id that belongs to
        // another company must be indistinguishable from one that does not
        // exist, and an explicit HTTP 404 says that identically on the plain
        // route and inside a Livewire round trip.
        return Quote::where('company_id', $conversation->company_id)
            ->whereKey($quoteId)
            ->firstOr(fn () => abort(404));
    }

    /** The same per-account budget the routes enforce, applied to Livewire. */
    private function allow(string $bucket, int $attempts, int $decay, string $errorField = 'body'): bool
    {
        $key = $bucket.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $this->addError($errorField, 'Too many attempts. Try again in a moment.');

            return false;
        }

        RateLimiter::hit($key, $decay);

        return true;
    }

    /* ------------------------------------------------------- message actions */

    public function openActions(int $messageId): void
    {
        $this->openActionsFor = $messageId;
    }

    public function closeActions(): void
    {
        $this->openActionsFor = null;
    }

    public function reply(int $messageId): void
    {
        $this->replyToId = $this->message($messageId)->getKey();
        $this->openActionsFor = null;
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    /** Delete your own prose. Soft delete: the row survives for audit. */
    public function deleteMessage(int $messageId): void
    {
        app(MessagingService::class)->delete($this->message($messageId), auth()->user());

        $this->openActionsFor = null;
    }

    /** Scoped lookup: a message id from another thread never resolves. */
    private function message(int $messageId): Message
    {
        $conversation = app(MessagingService::class)->find(auth()->user(), $this->conversationId);

        return $conversation->messages()->whereKey($messageId)->firstOrFail();
    }

    public function render(): View
    {
        $user = auth()->user();
        $messaging = app(MessagingService::class);

        $conversation = $messaging->find($user, $this->conversationId);
        $conversation->load(['company', 'user', 'product', 'order', 'participants']);

        $messages = $messaging->messages($conversation);

        // Opening (or polling) a thread you are looking at is reading it.
        $messaging->markRead($conversation, $user);

        return view('livewire.messaging.thread', [
            'user' => $user,
            'conversation' => $conversation,
            'messages' => $messages,
            'messaging' => $messaging,
            'replyTo' => $this->replyToId ? $messages->firstWhere('id', $this->replyToId) : null,
            // Which side this viewer is on, resolved once and handed to every
            // card, so no partial recomputes it (and none of them can get it
            // wrong in a way that would show the other side's buttons).
            'isBuyer' => (int) $conversation->user_id === (int) $user->getKey(),
            'unitOptions' => RfqUnit::options(),
            'formOptions' => collect(TimberForm::cases())->mapWithKeys(fn (TimberForm $f) => [$f->value => $f->label()])->all(),
            'incotermOptions' => collect(RfqIncoterm::cases())->mapWithKeys(fn (RfqIncoterm $i) => [$i->value => $i->label()])->all(),
        ]);
    }
}
