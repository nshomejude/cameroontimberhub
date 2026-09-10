<?php

namespace App\Listeners;

use App\Events\RfqRoutedToCompany;
use App\Jobs\SendRfqRoutingNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyExporterOfRfq implements ShouldQueue
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(RfqRoutedToCompany $event): void
    {
        SendRfqRoutingNotifications::dispatch($event->rfq, $event->company);
    }

    public function failed(RfqRoutedToCompany $event, ?\Throwable $e): void
    {
        Log::channel('errors')->error('NotifyExporterOfRfq failed permanently — RFQ routing notification job was never dispatched.', [
            'listener' => self::class,
            'rfq_id' => $event->rfq->id ?? null,
            'company_id' => $event->company->id ?? null,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }
}
