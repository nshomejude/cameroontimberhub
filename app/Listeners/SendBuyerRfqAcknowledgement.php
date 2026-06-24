<?php

namespace App\Listeners;

use App\Events\RfqApproved;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Placeholder for future buyer acknowledgement email when an RFQ is approved.
 * Buyers have no account in MVP; this hook exists so the listener is wired
 * in EventServiceProvider and can be fleshed out when buyer comms are added.
 */
class SendBuyerRfqAcknowledgement implements ShouldQueue
{
    public function handle(RfqApproved $event): void
    {
        // V2: send a "your request is being reviewed" email to $event->rfq->buyer_email
    }
}
