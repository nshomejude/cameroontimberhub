<?php

namespace App\Http\Controllers\Public;

use App\Enums\OrderDocumentKind;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\MessagingService;
use App\Services\OrderDocumentService;
use App\Services\OrderLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Plain-HTTP fallbacks for the in-thread order lifecycle actions, so every one
 * of them works with JavaScript off exactly as the Livewire path does.
 *
 * Same shape as ChatCommerceController: every route is POST and CSRF-protected,
 * the conversation is re-resolved through MessagingService (a non-participant
 * 404s before anything else happens), and the buyer/supplier rule is decided
 * exclusively by OrderLifecycleService. Nothing about authorisation is decided
 * in this class.
 *
 * A RuntimeException is a domain refusal — "you cannot ship an order that was
 * never confirmed", "you have already reviewed this order" — and comes back as
 * a flashed error. Authorisation failures are HttpExceptions and are left to
 * propagate as the 403 or 404 they are.
 */
class OrderLifecycleController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly OrderLifecycleService $lifecycle,
        private readonly OrderDocumentService $documents,
    ) {}

    /**
     * The printable proforma invoice sheet.
     *
     * Read-only, so GET is correct. It renders the ORDER SNAPSHOT — the same
     * immutable lines, currency and totals OrderService copied at award time —
     * and asserts nothing beyond them. It is not a tax invoice, carries no
     * invoice series of its own, and the sheet says both things in as many
     * words.
     */
    public function proformaSheet(Request $request, Conversation $conversation, int $order)
    {
        $user = $request->user();

        $conversation = $this->messaging->find($user, $conversation->getKey());
        $resolved = $this->lifecycle->threadOrder($conversation, $order);

        $resolved->load(['items', 'company']);

        return view('public.orders.proforma', [
            'order' => $resolved,
            'conversation' => $conversation,
        ]);
    }

    public function proforma(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->issueProformaInvoice($conv, $ord, $user), 'Proforma invoice shared.');
    }

    public function requestPayment(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $data = $request->validate([
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->requestPayment($conv, $ord, $user, $data['due_date'] ?? null, $data['reference'] ?? null),
            'Payment request sent.');
    }

    /**
     * Record an OFF-PLATFORM payment. This endpoint accepts an amount and a
     * free-text method name and nothing else — no card number, no bank
     * credential, no account identifier. There is no payment integration to
     * hand such data to, and collecting it would be indefensible.
     */
    public function recordPayment(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'method' => ['nullable', 'string', 'max:80'],
        ]);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->recordPayment($conv, $ord, $user, $data['amount'], $data['method'] ?? null),
            'Payment recorded.');
    }

    public function confirm(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->confirm($conv, $ord, $user), 'Order confirmed.');
    }

    public function startProduction(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->startProduction($conv, $ord, $user), 'Production started.');
    }

    public function ship(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $tracking = $this->trackingRules($request);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->ship($conv, $ord, $user, $tracking), 'Order marked as shipped.');
    }

    public function updateTracking(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $tracking = $this->trackingRules($request);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->updateTracking($conv, $ord, $user, $tracking), 'Shipment details updated.');
    }

    public function deliver(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $data = $request->validate([
            'received_by' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:200'],
            'proof' => ['nullable', 'array', 'max:5'],
            'proof.*' => $this->fileRules(),
        ]);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle->deliver(
            $conv,
            $ord,
            $user,
            $data['received_by'] ?? null,
            $data['location'] ?? null,
            $request->file('proof') ?? [],
        ), 'Delivery recorded.');
    }

    public function attachDocuments(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'in:'.implode(',', OrderDocumentKind::values())],
            'label' => ['nullable', 'string', 'max:160'],
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*' => $this->fileRules(),
        ]);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle->attachDocuments(
            $conv,
            $ord,
            $user,
            $request->file('documents') ?? [],
            OrderDocumentKind::tryFrom($data['kind'] ?? '') ?? OrderDocumentKind::Other,
            $data['label'] ?? null,
        ), 'Documents attached.');
    }

    public function complete(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->complete($conv, $ord, $user), 'Transaction completed.');
    }

    public function review(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->run($request, $conversation, $order, fn ($conv, $ord, $user) => $this->lifecycle
            ->review($conv, $ord, $user, $data), 'Thank you — your review has been published.');
    }

    /* -------------------------------------------------------------- plumbing */

    /**
     * Server-side file rules, mirroring OrderDocumentService's allow-list so a
     * bad upload is rejected at the edge as well as in the service.
     *
     * @return list<mixed>
     */
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
            // `url` plus an explicit scheme check; Order::trackingLink() refuses
            // anything non-http(s) again at render time.
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
     * Resolve, act, redirect. The conversation is resolved through
     * MessagingService (404 for a stranger) and the order through
     * OrderLifecycleService::threadOrder() (404 for an id from another thread).
     */
    private function run(
        Request $request,
        Conversation $conversation,
        int $orderId,
        callable $action,
        string $success,
    ): RedirectResponse {
        $user = $request->user();

        $conversation = $this->messaging->find($user, $conversation->getKey());
        $order = $this->lifecycle->threadOrder($conversation, $orderId);

        try {
            $action($conversation, $order, $user);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $success);
    }
}
