<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Rfq;
use App\Notifications\RfqRoutedToExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class SendRfqRoutingNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly Rfq $rfq,
        public readonly Company $company,
    ) {}

    public function handle(): void
    {
        Notification::send(
            $this->company->users,
            new RfqRoutedToExporter($this->rfq, $this->company),
        );
    }
}
