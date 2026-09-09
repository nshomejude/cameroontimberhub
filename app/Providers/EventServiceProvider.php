<?php

namespace App\Providers;

use App\Domain\Compliance\Events\ComplianceCaseOpened;
use App\Domain\Logistics\Events\ShipmentCheckpointRecorded;
use App\Domain\Trade\Events\OrderAwarded;
use App\Events\BadgeIssued;
use App\Events\BadgeRevoked;
use App\Events\CompanyVerified;
use App\Events\DocumentApproved;
use App\Events\DocumentRejected;
use App\Events\PlanAssigned;
use App\Events\RfqApproved;
use App\Events\RfqRoutedToCompany;
use App\Listeners\DetectLoginAnomaly;
use App\Listeners\NotifyExporterOfRfq;
use App\Listeners\NotifyExporterOfVerification;
use App\Listeners\OpenComplianceCaseOnOrderAwarded;
use App\Listeners\RecordLotEventOnShipmentCheckpoint;
use App\Listeners\SendBuyerRfqAcknowledgement;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected $listen = [
        CompanyVerified::class => [
            NotifyExporterOfVerification::class,
        ],

        // Blueprint §25 login-anomaly detection (detection/alerting only).
        Login::class => [
            DetectLoginAnomaly::class,
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

        // Outbox-relayed domain events (architecture plan, Task 0.2). These
        // are dispatched by App\Jobs\RelayOutboxEventsJob, not synchronously
        // in-request — the listeners below are queued (ShouldQueue).
        OrderAwarded::class => [
            OpenComplianceCaseOnOrderAwarded::class,
        ],
        ShipmentCheckpointRecorded::class => [
            RecordLotEventOnShipmentCheckpoint::class,
        ],
        // No listener yet — dispatched for future consumers (webhooks,
        // notifications), same pattern as DocumentApproved/BadgeIssued above.
        ComplianceCaseOpened::class => [],
    ];
}
