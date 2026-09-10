<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Rfq;
use App\Notifications\RfqRoutedToExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendRfqRoutingNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('errors')->error('SendRfqRoutingNotifications failed permanently — an exporter was not notified of a routed RFQ.', [
            'job' => self::class,
            'rfq_id' => $this->rfq->id ?? null,
            'company_id' => $this->company->id ?? null,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }

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
