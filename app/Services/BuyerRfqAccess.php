<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The buyer-side access model for RFQ responses.
 *
 * RFQ intake is deliberately account-free, so there are exactly two ways a
 * buyer reaches their own responses — and no third:
 *
 *  1. **Signed link.** A long-lived Laravel signed URL, emailed to the address
 *     that submitted the RFQ. The signature covers the RFQ id (and the quote id
 *     on quote URLs), so a holder of one link cannot walk to another RFQ by
 *     editing the path. A `h` parameter pins the link to a sha1 of the buyer
 *     address at the time it was issued, so changing the address invalidates
 *     every outstanding link.
 *
 *  2. **Account link.** When `rfqs.user_id` is set — because the buyer's email
 *     matched a registered user at creation, or was backfilled when that
 *     address registered — the signed-in owner reaches the same screens with no
 *     signature at all.
 *
 * Anything else is a 403. This class is the single decision point; controllers
 * never re-implement the check.
 */
class BuyerRfqAccess
{
    /** Signed links stay valid for the life of the RFQ conversation. */
    public function responsesUrl(Rfq $rfq): string
    {
        return URL::signedRoute('buyer.rfq.responses', $this->params($rfq));
    }

    public function quoteUrl(Rfq $rfq, Quote $quote): string
    {
        return URL::signedRoute('buyer.rfq.quote', $this->params($rfq, $quote));
    }

    public function acceptUrl(Rfq $rfq, Quote $quote): string
    {
        return URL::signedRoute('buyer.rfq.quote.accept', $this->params($rfq, $quote));
    }

    public function declineUrl(Rfq $rfq, Quote $quote): string
    {
        return URL::signedRoute('buyer.rfq.quote.decline', $this->params($rfq, $quote));
    }

    /** The award review screen for one quote (GET; the award itself is a POST). */
    public function awardUrl(Rfq $rfq, Quote $quote): string
    {
        return URL::signedRoute('buyer.rfq.quote.award', $this->params($rfq, $quote));
    }

    /** The order that resulted from the award on this RFQ. */
    public function orderUrl(Rfq $rfq): string
    {
        return URL::signedRoute('buyer.rfq.order', $this->params($rfq));
    }

    /** The printable receipt for that order. */
    public function receiptUrl(Rfq $rfq): string
    {
        return URL::signedRoute('buyer.rfq.order.receipt', $this->params($rfq));
    }

    /**
     * Build a link appropriate to how the current visitor got here: signed for
     * a guest holding a signed link, plain for the signed-in owner (so we never
     * splash a shareable signature into an authenticated buyer's address bar).
     */
    public function link(Request $request, string $kind, Rfq $rfq, ?Quote $quote = null): string
    {
        $route = match ($kind) {
            'responses' => 'buyer.rfq.responses',
            'quote' => 'buyer.rfq.quote',
            'accept' => 'buyer.rfq.quote.accept',
            'decline' => 'buyer.rfq.quote.decline',
            'award' => 'buyer.rfq.quote.award',
            'order' => 'buyer.rfq.order',
            'receipt' => 'buyer.rfq.order.receipt',
        };

        if ($this->isAccountOwner($request, $rfq)) {
            return route($route, $this->params($rfq, $quote, withHash: false));
        }

        return URL::signedRoute($route, $this->params($rfq, $quote));
    }

    /**
     * The one authorisation decision. True when this request may see this RFQ.
     */
    public function allows(Request $request, Rfq $rfq): bool
    {
        if ($this->isAccountOwner($request, $rfq)) {
            return true;
        }

        return $request->hasValidSignature()
            && is_string($request->query('h'))
            && hash_equals($this->hash($rfq), (string) $request->query('h'));
    }

    public function authorize(Request $request, Rfq $rfq): void
    {
        abort_unless($this->allows($request, $rfq), 403);
    }

    public function isAccountOwner(Request $request, Rfq $rfq): bool
    {
        $user = $request->user();

        return $user !== null
            && $rfq->user_id !== null
            && (int) $rfq->user_id === (int) $user->getKey();
    }

    /** Ties a signed link to the address it was issued for. */
    public function hash(Rfq $rfq): string
    {
        return sha1(strtolower(trim((string) $rfq->buyer_email)));
    }

    /** @return array<string, mixed> */
    private function params(Rfq $rfq, ?Quote $quote = null, bool $withHash = true): array
    {
        $params = ['rfq' => $rfq->getKey()];

        if ($quote) {
            $params['quote'] = $quote->getKey();
        }

        if ($withHash) {
            $params['h'] = $this->hash($rfq);
        }

        return $params;
    }
}
