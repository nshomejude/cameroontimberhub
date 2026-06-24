<?php

namespace App\Listeners;

use App\Events\CompanyVerified;
use App\Notifications\CompanyVerifiedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class NotifyExporterOfVerification implements ShouldQueue
{
    public function handle(CompanyVerified $event): void
    {
        Notification::send(
            $event->company->users,
            new CompanyVerifiedNotification($event->company),
        );
    }
}
