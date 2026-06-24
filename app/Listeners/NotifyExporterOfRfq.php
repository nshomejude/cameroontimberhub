<?php

namespace App\Listeners;

use App\Events\RfqRoutedToCompany;
use App\Jobs\SendRfqRoutingNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyExporterOfRfq implements ShouldQueue
{
    public function handle(RfqRoutedToCompany $event): void
    {
        SendRfqRoutingNotifications::dispatch($event->rfq, $event->company);
    }
}
