<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The API's buyer-scoping boundary — the token-auth counterpart to
 * BuyerRfqAccess (which exists for the account-free, signed-link web path).
 *
 * There is exactly one way a token holder reaches an RFQ here: `rfqs.user_id`
 * is their own id. No signed links, no reference-code lookups that ignore
 * ownership, no id enumeration.
 *
 * Everything resolves with `firstOrFail()` over an already-scoped query, so a
 * record that exists but belongs to someone else is indistinguishable from one
 * that does not exist: both are 404. A 403 would confirm that a given reference
 * code is real, which is exactly what a reference-grinder wants to learn.
 */
class BuyerApiScope
{
    /** Every RFQ this buyer owns, newest first. */
    public function rfqs(User $buyer): Builder
    {
        return Rfq::query()
            ->where('user_id', $buyer->getKey())
            ->orderByDesc('created_at');
    }

    /** One of the buyer's own RFQs by reference code, or 404. */
    public function rfq(User $buyer, string $reference): Rfq
    {
        return $this->rfqs($buyer)
            ->where('reference_code', $reference)
            ->firstOrFail();
    }

    /**
     * Quotes the buyer may see: submitted/viewed/accepted/declined/expired
     * quotes on RFQs they own. Drafts and withdrawn quotes never leave the
     * supplier's side (Quote::scopeBuyerVisible).
     */
    public function quotes(User $buyer): Builder
    {
        return Quote::query()
            ->buyerVisible()
            ->whereHas('rfq', fn (Builder $r) => $r->where('user_id', $buyer->getKey()));
    }

    /** One buyer-visible quote by reference code, or 404. */
    public function quote(User $buyer, string $reference): Quote
    {
        return $this->quotes($buyer)
            ->where('reference_code', $reference)
            ->firstOrFail();
    }

    /**
     * Orders this buyer owns — via `BuyerDashboard::orders()`, the same
     * scoping `/account/orders` (and ListBuyerOrdersQuery, which delegates to
     * it) already uses: any order on an RFQ the buyer owns, not merely one
     * whose own `orders.user_id` snapshot matches. That is deliberately
     * broader than an `orders.user_id` check alone — see that method's
     * docblock — so a guest RFQ adopted into an account after award still
     * resolves here exactly as it does on the web account page, and `show()`
     * never 404s an order this buyer can already see in their own list.
     */
    public function orders(User $buyer): Builder
    {
        return app(BuyerDashboard::class)->orders($buyer)->orderByDesc('created_at');
    }

    /** One of the buyer's own orders by reference code, or 404. */
    public function order(User $buyer, string $reference): Order
    {
        return $this->orders($buyer)
            ->where('reference_code', $reference)
            ->firstOrFail();
    }

    /**
     * Live (non-voided) receipts for this buyer's own orders — via
     * `BuyerDashboard::receipts()`, the same query `/account/receipts`
     * already uses. A voided receipt never appears here: void status is a
     * post-issuance correction (fraud, duplicate, reversed order), so it is
     * not a valid receipt to hand back to a buyer any more, even though the
     * hash-chain row itself is immutable and stays on record for the
     * verification endpoint.
     */
    public function receipts(User $buyer): Builder
    {
        return app(BuyerDashboard::class)->receipts($buyer)->orderByDesc('issued_at');
    }

    /**
     * One of the buyer's own LIVE receipts by receipt number, or 404 — for
     * a voided receipt's number and for another buyer's receipt number
     * alike, so neither is distinguishable from a number that was never
     * issued at all (same enumeration-safety convention as `order()`).
     */
    public function receipt(User $buyer, string $receiptNumber): Receipt
    {
        return $this->receipts($buyer)
            ->where('receipt_number', $receiptNumber)
            ->firstOrFail();
    }
}
