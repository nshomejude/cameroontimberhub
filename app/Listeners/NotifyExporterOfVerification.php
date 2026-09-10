<?php

namespace App\Listeners;

use App\Events\CompanyVerified;
use App\Notifications\CompanyVerifiedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotifyExporterOfVerification implements ShouldQueue
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CompanyVerified $event): void
    {
        Notification::send(
            $event->company->users,
            new CompanyVerifiedNotification($event->company),
        );
    }

    public function failed(CompanyVerified $event, ?\Throwable $e): void
    {
        Log::channel('errors')->error('NotifyExporterOfVerification failed permanently — company was not notified it is verified.', [
            'listener' => self::class,
            'company_id' => $event->company->id ?? null,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }
}
