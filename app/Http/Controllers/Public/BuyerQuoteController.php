<?php

namespace App\Http\Controllers\Public;

use App\Domain\Trade\Commands\AwardQuoteCommand;
use App\Domain\Trade\Commands\DeclineQuoteCommand;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\Rfq;
use App\Services\BuyerRfqAccess;
use App\Services\QuoteService;
use App\Support\Bus\CommandBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * The two buyer-facing quote screens.
 *
 * Access is decided in exactly one place — BuyerRfqAccess — and every query is
 * scoped to the authorised RFQ, so there is no path from one buyer's link to
 * another buyer's data. Quotes are additionally filtered to the buyer-visible
 * statuses, so supplier drafts and withdrawn quotes never appear.
 */
class BuyerQuoteController extends Controller
{
    public function __construct(private readonly BuyerRfqAccess $access) {}

    /** Screen 1 — every response received for one RFQ. */
    public function index(Request $request, Rfq $rfq): View
    {
        $this->access->authorize($request, $rfq);

        $rfq->load('items.species');

        $sort = in_array($request->query('sort'), ['total', 'lead_time', 'validity', 'newest'], true)
            ? $request->query('sort')
            : 'total';

        $quotes = $rfq->quotes()
            ->buyerVisible()
            ->with(['company', 'items.species'])
            ->get()
            ->sortBy(fn (Quote $q) => match ($sort) {
                'lead_time' => $q->lead_time_days ?? PHP_INT_MAX,
                'validity' => $q->valid_until?->timestamp ?? PHP_INT_MAX,
                'newest' => -($q->submitted_at?->timestamp ?? 0),
                default => (float) $q->total_amount,
            })
            ->values();

        // "Lowest total" is computed from real submitted figures — the only
        // comparison badge on this page, and only when it is unambiguous.
        $open = $quotes->filter(fn (Quote $q) => $q->isActionable());
        $lowestTotal = $open->count() > 1 ? (float) $open->min('total_amount') : null;

        return view('public.rfq.responses', [
            'rfq' => $rfq,
            'quotes' => $quotes,
            'sort' => $sort,
            'lowestTotal' => $lowestTotal,
            'accepted' => $quotes->firstWhere('status', QuoteStatus::Accepted),
            'access' => $this->access,
        ]);
    }

    /** Screen 2 — one quote in full, with the accept / decline actions. */
    public function show(Request $request, Rfq $rfq, Quote $quote, QuoteService $quotes): View
    {
        $this->access->authorize($request, $rfq);
        $quote = $this->scopedQuote($rfq, $quote);

        // Opening the quote is what marks it seen by the buyer.
        $quote = $quotes->markViewed($quote);
        $quote->load(['company', 'items.species', 'rfq.items.species']);

        return view('public.rfq.quote', [
            'rfq' => $rfq,
            'quote' => $quote,
            'access' => $this->access,
            'siblingCount' => $rfq->quotes()->buyerVisible()->count(),
        ]);
    }

    /** POST — award. Never a GET link; confirmation is a required checkbox. */
    public function accept(Request $request, Rfq $rfq, Quote $quote, CommandBus $commands): RedirectResponse
    {
        $this->access->authorize($request, $rfq);
        $quote = $this->scopedQuote($rfq, $quote);

        $request->validate([
            'confirm' => ['accepted'],
        ], [], ['confirm' => 'confirmation']);

        try {
            $commands->dispatch(new AwardQuoteCommand(
                quoteId: $quote->getKey(),
                actingUserId: $request->user()?->getKey(),
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirm' => $e->getMessage()]);
        }

        // The award produced an order (same transaction), so the buyer lands on
        // it rather than back on the comparison screen.
        return redirect()->to($this->access->link($request, 'order', $rfq))
            ->with('quote_notice', 'You awarded this request to '.$quote->company->name.'. Every other quote has been declined.');
    }

    /** POST — decline one quote. A reason is mandatory. */
    public function decline(Request $request, Rfq $rfq, Quote $quote, CommandBus $commands): RedirectResponse
    {
        $this->access->authorize($request, $rfq);
        $quote = $this->scopedQuote($rfq, $quote);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['reason' => 'reason']);

        try {
            $commands->dispatch(new DeclineQuoteCommand(
                quoteId: $quote->getKey(),
                reason: $data['reason'],
                actingUserId: $request->user()?->getKey(),
            ));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return redirect()->to($this->access->link($request, 'responses', $rfq))
            ->with('quote_notice', 'Quote '.$quote->reference_code.' was declined.');
    }

    /**
     * Re-resolves the quote *within* the authorised RFQ and within the
     * buyer-visible statuses. Implicit binding alone would happily hand over a
     * quote belonging to a different RFQ, so it is never trusted directly.
     */
    private function scopedQuote(Rfq $rfq, Quote $quote): Quote
    {
        return $rfq->quotes()->buyerVisible()->whereKey($quote->getKey())->firstOrFail();
    }
}
