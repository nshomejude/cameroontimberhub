<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageType;
use App\Enums\OrderDocumentKind;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Rfq;
use App\Services\MessagingService;
use App\Services\OrderDocumentService;
use App\Services\OrderLifecycleService;
use App\Services\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The order lifecycle as it is driven from inside a conversation, over token
 * auth — the API counterpart of `Http\Controllers\Public\OrderLifecycleController`
 * and `Http\Controllers\Public\ReorderController::quote()`.
 *
 * Same any-participant conversation group as `ChatCommerceController` (see
 * that class's docblock for why: the buyer/supplier split is a property of
 * the thread, decided by `OrderLifecycleService`/`ChatCommerceService`, never
 * by route middleware). `MessagingService::find()` 404s a non-participant
 * before anything else runs; `OrderLifecycleService::threadOrder()` 404s an
 * order id that does not belong to THIS thread, so a stranger to a
 * conversation cannot fish for another buyer's order data by id-guessing on
 * a conversation they were let into some other way.
 *
 * Every write here is POST; the one GET (`proforma`) changes nothing — it
 * returns the SAME order snapshot the printable web sheet renders, reshaped
 * as JSON data instead of an HTML document, because a mobile client has
 * nothing to do with a `<table>`.
 *
 * A `RuntimeException` from the service is a domain refusal and becomes a 409
 * `ConflictException` — see `ChatCommerceController`'s docblock for why
 * `HttpExceptionInterface` (403 role checks, 404 thread-ownership checks)
 * must be re-thrown BEFORE that catch, not after: `Public\OrderLifecycleController::run()`
 * is verified to use the identical ordering.
 */
class ChatOrderController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly OrderLifecycleService $lifecycle,
        private readonly OrderDocumentService $documents,
        private readonly ReorderService $reorders,
    ) {}

    /**
     * The proforma invoice sheet as structured data — the same figures
     * `Public\OrderLifecycleController::proformaSheet()` renders as HTML, read
     * straight off the order snapshot (never off a `proforma_invoice`
     * message — the order may not have one yet, and the figures are
     * identical either way).
     */
    public function proformaSheet(Request $request, int $id, int $order): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);
        $resolved = $this->lifecycle->threadOrder($conversation, $order);
        $resolved->load(['items', 'company']);

        return response()->json(['data' => [
            'reference_code' => $resolved->reference_code,
            'currency' => $resolved->currency->value,
            'subtotal_amount' => (string) $resolved->subtotal_amount,
            'shipping_amount' => (string) $resolved->shipping_amount,
            'tax_amount' => (string) $resolved->tax_amount,
            'total_amount' => (string) $resolved->total_amount,
            'supplier_name' => $resolved->supplier_name,
            'buyer_name' => $resolved->buyer_name,
            'buyer_company' => $resolved->buyer_company,
            'incoterm' => $resolved->incoterm?->value,
            'payment_terms' => $resolved->payment_terms,
            'lead_time_days' => $resolved->lead_time_days,
            'shipping_port' => $resolved->shipping_port,
            'status' => $resolved->status->value,
            'items' => $resolved->items->map(fn ($item) => [
                'description' => $item->description,
                'species_name' => $item->species_name,
                'grade' => $item->grade,
                'dimensions' => $item->dimensions,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
            ]),
        ]]);
    }

    /** Supplier issues the proforma invoice CARD into the thread. */
    public function proforma(Request $request, int $id, int $order): JsonResponse
    {
        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->issueProformaInvoice($c, $o, $user), 'order_action_not_allowed');
    }

    public function requestPayment(Request $request, int $id, int $order): JsonResponse
    {
        $data = $request->validate([
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->requestPayment($c, $o, $user, $data['due_date'] ?? null, $data['reference'] ?? null),
            'order_action_not_allowed');
    }

    /**
     * Record an OFF-PLATFORM payment. Same posture as the web route: an
     * amount and a free-text method name only, never a card number, a bank
     * credential, or an account identifier — there is no payment integration
     * to hand such data to.
     */
    public function recordPayment(Request $request, int $id, int $order): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'method' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->recordPayment($c, $o, $user, $data['amount'], $data['method'] ?? null),
            'order_action_not_allowed');
    }

    public function confirm(Request $request, int $id, int $order): JsonResponse
    {
        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->confirm($c, $o, $user), 'order_transition_not_allowed');
    }

    public function startProduction(Request $request, int $id, int $order): JsonResponse
    {
        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->startProduction($c, $o, $user), 'order_transition_not_allowed');
    }

    public function ship(Request $request, int $id, int $order): JsonResponse
    {
        $tracking = $this->trackingRules($request);

        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->ship($c, $o, $user, $tracking), 'order_transition_not_allowed');
    }

    public function updateTracking(Request $request, int $id, int $order): JsonResponse
    {
        $tracking = $this->trackingRules($request);

        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->updateTracking($c, $o, $user, $tracking), 'order_action_not_allowed');
    }

    public function deliver(Request $request, int $id, int $order): JsonResponse
    {
        $data = $request->validate([
            'received_by' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:200'],
            'proof' => ['nullable', 'array', 'max:5'],
            'proof.*' => $this->fileRules(),
        ]);

        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle->deliver(
            $c,
            $o,
            $user,
            $data['received_by'] ?? null,
            $data['location'] ?? null,
            $request->file('proof') ?? [],
        ), 'order_transition_not_allowed');
    }

    public function attachDocuments(Request $request, int $id, int $order): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'in:'.implode(',', OrderDocumentKind::values())],
            'label' => ['nullable', 'string', 'max:160'],
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*' => $this->fileRules(),
        ]);

        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle->attachDocuments(
            $c,
            $o,
            $user,
            $request->file('documents') ?? [],
            OrderDocumentKind::tryFrom($data['kind'] ?? '') ?? OrderDocumentKind::Other,
            $data['label'] ?? null,
        ), 'order_action_not_allowed');
    }

    public function complete(Request $request, int $id, int $order): JsonResponse
    {
        return $this->run($request, $id, $order, fn (Conversation $c, Order $o, $user) => $this->lifecycle
            ->complete($c, $o, $user), 'order_transition_not_allowed');
    }

    public function review(Request $request, int $id, int $order): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $conversation = $this->messaging->find($request->user(), $id);
        $resolved = $this->lifecycle->threadOrder($conversation, $order);
        $user = $request->user();

        $review = $this->act(fn () => $this->lifecycle->review($conversation, $resolved, $user, $data), 'review_not_eligible');

        $message = $review->message_id ? Message::find($review->message_id) : null;
        $message?->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => $message ? new MessageResource($message) : null], 201);
    }

    /**
     * Supplier prices a reorder request. Keyed by the reorder RFQ, not the
     * order, because at this point no new order exists yet — the same reason
     * the web route (`chat.reorder.quote`) is keyed the same way.
     */
    public function reorderQuote(Request $request, int $id, Rfq $rfq): JsonResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);
        $user = $request->user();

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $quote = $this->act(fn () => $this->reorders->quote($conversation, $rfq, $user, $data), 'reorder_not_quotable');

        $message = $conversation->messages()
            ->where('type', MessageType::Quotation->value)
            ->where('related_type', $quote->getMorphClass())
            ->where('related_id', $quote->getKey())
            ->latest('id')
            ->first();

        $message?->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => $message ? new MessageResource($message) : null], 201);
    }

    /**
     * Download an order document from inside the thread.
     *
     * Unlike `Api\V1\OrderDocumentController::download()` (buyer-only, via
     * `BuyerApiScope`), this is reachable by BOTH sides of the thread — the
     * same rule `OrderLifecycleService::mayAccessDocument()` already states
     * for the web download controller (buyer OR a member of the supplying
     * company). A conversation participant on this order's thread already
     * satisfies one half of that rule by construction (they are either the
     * order's buyer or a member of its supplying company — `threadOrder()`
     * ties the order to both sides of THIS conversation), so re-resolving
     * through the conversation is not a widening of `mayAccessDocument()`,
     * it is the same rule reached from a different, already-authorised door.
     */
    public function downloadDocument(Request $request, int $id, int $order, int $document): StreamedResponse
    {
        $conversation = $this->messaging->find($request->user(), $id);
        $resolved = $this->lifecycle->threadOrder($conversation, $order);

        $doc = $resolved->documents()->whereKey($document)->firstOr(fn () => abort(404));

        return $this->documents->download($doc);
    }

    /* -------------------------------------------------------------- plumbing */

    /** @return list<mixed> */
    private function fileRules(): array
    {
        return [
            'file',
            'mimetypes:'.implode(',', OrderDocumentService::ALLOWED_MIMES),
            'mimes:'.implode(',', OrderDocumentService::ALLOWED_EXTENSIONS),
            'max:'.(OrderDocumentService::MAX_BYTES / 1024),
        ];
    }

    /** @return array<string, mixed> */
    private function trackingRules(Request $request): array
    {
        return $request->validate([
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'url:http,https', 'max:500'],
            'shipping_method' => ['nullable', 'string', 'max:120'],
            'vessel_name' => ['nullable', 'string', 'max:120'],
            'voyage_number' => ['nullable', 'string', 'max:60'],
            'container_number' => ['nullable', 'string', 'max:60'],
            'port_of_loading' => ['nullable', 'string', 'max:120'],
            'port_of_discharge' => ['nullable', 'string', 'max:120'],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
        ]);
    }

    /**
     * Resolve, act, return the resulting card.
     *
     * The conversation is resolved through `MessagingService` (404 for a
     * stranger) and the order through `OrderLifecycleService::threadOrder()`
     * (404 for an id from another thread) — identical to the web controller's
     * `run()`.
     */
    private function run(Request $request, int $id, int $orderId, callable $action, string $code): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->messaging->find($user, $id);
        $order = $this->lifecycle->threadOrder($conversation, $orderId);

        $message = $this->act(fn () => $action($conversation, $order, $user), $code);

        $message->loadMissing('sender', 'senderCompany');

        return response()->json(['data' => new MessageResource($message)], 201);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function act(callable $action, string $code)
    {
        try {
            return $action();
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), $code, $e);
        }
    }
}
