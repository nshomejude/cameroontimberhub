<?php

namespace App\Services;

use App\Models\Quote;
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
}
