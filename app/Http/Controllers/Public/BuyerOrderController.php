<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Services\BuyerRfqAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer-facing order screens: award review, order confirmation, receipt.
 *
 * Access reuses BuyerRfqAccess exactly as the quote screens do — signed link or
 * signed-in RFQ owner, nothing else — and every record is re-resolved through
 * the authorised RFQ, so an id in the URL can never reach another buyer's data.
 */
class BuyerOrderController extends Controller
{
    public function __construct(private readonly BuyerRfqAccess $access) {}

    /**
     * Screen 1 — "Award Order": review exactly what is about to be committed.
     *
     * This is a read-only review page. The award itself is the existing POST
     * `buyer.rfq.quote.accept`, with CSRF and a required confirmation checkbox;
     * there is no GET route anywhere that awards an order.
     */
    public function award(Request $request, Rfq $rfq, Quote $quote): View
    {
        $this->access->authorize($request, $rfq);

        $quote = $rfq->quotes()->buyerVisible()->whereKey($quote->getKey())->firstOrFail();
        $quote->load(['company', 'items.species']);

        // Already awarded? The review page has nothing left to review.
        if ($order = Order::where('quote_id', $quote->getKey())->first()) {
            return $this->renderOrder($request, $rfq, $order);
        }

        return view('public.orders.award', [
            'rfq' => $rfq,
            'quote' => $quote,
            'access' => $this->access,
            'siblingCount' => $rfq->quotes()->buyerVisible()->count(),
        ]);
    }

    /** Screen 2 — the order itself, after the award. */
    public function show(Request $request, Rfq $rfq): View
    {
        $this->access->authorize($request, $rfq);

        return $this->renderOrder($request, $rfq, $this->orderFor($rfq));
    }

    /** Screen 3 — the printable B2B receipt document. */
    public function receipt(Request $request, Rfq $rfq): View
    {
        $this->access->authorize($request, $rfq);

        $order = $this->orderFor($rfq);
        $order->load(['items', 'company', 'receipt']);

        return view('public.orders.receipt', [
            'rfq' => $rfq,
            'order' => $order,
            'receipt' => $order->receipt,
            'access' => $this->access,
        ]);
    }

    /* ------------------------------------------------------------ internals */

    private function renderOrder(Request $request, Rfq $rfq, Order $order): View
    {
        $order->load(['items', 'company', 'quote', 'receipt']);

        return view('public.orders.show', [
            'rfq' => $rfq,
            'order' => $order,
            'receipt' => $order->receipt,
            'access' => $this->access,
        ]);
    }

    /** Scoped to the authorised RFQ — never resolved from a route id. */
    private function orderFor(Rfq $rfq): Order
    {
        return $rfq->orders()->latest('id')->firstOrFail();
    }
}
