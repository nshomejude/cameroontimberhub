<?php

namespace App\Listeners;

use App\Events\RfqApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder for future buyer acknowledgement email when an RFQ is approved.
 * Buyers have no account in MVP; this hook exists so the listener is wired
 * in EventServiceProvider and can be fleshed out when buyer comms are added.
 */
class SendBuyerRfqAcknowledgement implements ShouldQueue
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(RfqApproved $event): void
    {
        // V2: send a "your request is being reviewed" email to $event->rfq->buyer_email
    }

    public function failed(RfqApproved $event, ?\Throwable $e): void
    {
        Log::channel('errors')->error('SendBuyerRfqAcknowledgement failed permanently.', [
            'listener' => self::class,
            'rfq_id' => $event->rfq->id ?? null,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }
}
