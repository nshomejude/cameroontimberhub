<?php

namespace App\Listeners;

use App\Domain\Compliance\Events\ComplianceCaseOpened;
use App\Domain\Trade\Events\OrderAwarded;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceRule;
use App\Models\Order;
use App\Support\Events\RecordsOutboxEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Blueprint §15/§17 compliance wiring, formalized off OrderAwarded.
 *
 * This is exactly what App\Observers\OrderObserver::created() used to do
 * inline and synchronously: if any active ComplianceRule applies to the
 * order's destination country, open a ComplianceCase (status:
 * not_assessed) for staff to work. If no rule applies, no case is created.
 *
 * Queued (ShouldQueue), so this now runs asynchronously off the outbox
 * relay rather than in-request. Deliberately never throws out of handle():
 * opening a compliance case must never surface as a failed queue job that
 * blocks the outbox relay from marking other events published.
 */
class OpenComplianceCaseOnOrderAwarded implements ShouldQueue
{
    use RecordsOutboxEvents;

    public function handle(OrderAwarded $event): void
    {
        try {
            $this->process($event);
        } catch (Throwable $e) {
            Log::error('OpenComplianceCaseOnOrderAwarded: failed to evaluate/create compliance case for order.', [
                'order_id' => $event->orderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function process(OrderAwarded $event): void
    {
        if ($event->countryCode === null) {
            return;
        }

        $hasApplicableRule = ComplianceRule::query()
            ->applicableTo($event->countryCode)
            ->exists();

        if (! $hasApplicableRule) {
            return;
        }

        DB::transaction(function () use ($event) {
            $case = ComplianceCase::query()->create([
                'owner_type' => Order::class,
                'owner_id' => $event->orderId,
                'status' => ComplianceCaseStatus::NotAssessed,
                'country_code' => $event->countryCode,
                'opened_at' => now(),
            ]);

            $this->recordOutboxEvent(new ComplianceCaseOpened(
                complianceCaseId: $case->id,
                ownerType: Order::class,
                ownerId: $event->orderId,
                countryCode: $event->countryCode,
            ));
        });
    }
}
