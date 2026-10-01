<?php

namespace App\Actions\Auth;

use App\Models\Rfq;
use App\Models\User;

/**
 * Attaches account-free (guest) RFQs submitted under a user's email address
 * to that user's account.
 *
 * Only ever run for a VERIFIED address: adopting by an unverified email would
 * let anyone register with someone else's address and take over their RFQs
 * (and the supplier quotes attached to them). Called from the Verified event
 * listener, never at registration time.
 */
class AdoptGuestRfqs
{
    /** @return int Number of RFQs adopted. */
    public function __invoke(User $user): int
    {
        if (! $user->hasVerifiedEmail()) {
            return 0;
        }

        return Rfq::whereNull('user_id')
            ->whereRaw('lower(buyer_email) = ?', [strtolower(trim((string) $user->email))])
            ->update(['user_id' => $user->id]);
    }
}
