<?php

namespace App\Providers;

use App\Events\BadgeIssued;
use App\Events\BadgeRevoked;
use App\Events\CompanyVerified;
use App\Events\DocumentApproved;
use App\Events\DocumentRejected;
use App\Events\PlanAssigned;
use App\Events\RfqApproved;
use App\Events\RfqRoutedToCompany;
use App\Listeners\NotifyExporterOfRfq;
use App\Listeners\NotifyExporterOfVerification;
use App\Listeners\SendBuyerRfqAcknowledgement;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected $listen = [
        CompanyVerified::class => [
            NotifyExporterOfVerification::class,
        ],

        RfqRoutedToCompany::class => [
            NotifyExporterOfRfq::class,
        ],

        RfqApproved::class => [
            SendBuyerRfqAcknowledgement::class,
        ],

        // These events are dispatched for future listeners (analytics, webhooks, etc.).
        DocumentApproved::class  => [],
        DocumentRejected::class  => [],
        BadgeIssued::class       => [],
        BadgeRevoked::class      => [],
        PlanAssigned::class      => [],
    ];
}
