<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Rfq;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;
use App\Services\ReorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Plain-HTTP fallbacks for the in-thread reorder actions, so both sides work
 * with JavaScript off exactly as the Livewire path does.
 *
 * Same shape as the Phase 2 and Phase 3 controllers: every route is POST and
 * CSRF-protected, the conversation is re-resolved through MessagingService (a
 * non-participant 404s before anything else happens), and the buyer/supplier
 * rule is decided exclusively by ReorderService. Nothing about authorisation is
 * decided in this class.
 *
 * Note what the buyer's endpoint validates: quantities and text. There is no
 * price rule here because there is no price field — a `unit_price` posted to
 * `store()` is neither validated nor read, and the reorder RFQ has nowhere to
 * put it. Prices are accepted only from the SUPPLIER, in `quote()`, where they
 * are required rather than optional.
 */
class ReorderController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly OrderLifecycleService $lifecycle,
        private readonly ReorderService $reorders,
    ) {}

    /** Buyer asks the supplier to repeat a past order. */
    public function store(Request $request, Conversation $conversation, int $order): RedirectResponse
    {
        $data = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'shipping_port' => ['nullable', 'string', 'max:120'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $conversation = $this->messaging->find($user, $conversation->getKey());
        $source = $this->lifecycle->threadOrder($conversation, $order);

        try {
            $this->reorders->request($conversation, $source, $user, $data);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Reorder request sent. The supplier will confirm pricing.');
    }

    /**
     * Supplier prices the reorder request.
     *
     * A unit price for every line is REQUIRED. That is the whole point of this
     * endpoint: it is the confirmation step that turns a repeat request into a
     * real, current offer, and it can only be reached by the supplier.
     */
    public function quote(Request $request, Conversation $conversation, Rfq $rfq): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $user = $request->user();
        $conversation = $this->messaging->find($user, $conversation->getKey());

        try {
            // ReorderService re-checks that this RFQ is a reorder of an order
            // belonging to both sides of this thread, and 404s otherwise.
            $this->reorders->quote($conversation, $rfq, $user, $data);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Quotation sent.');
    }
}
