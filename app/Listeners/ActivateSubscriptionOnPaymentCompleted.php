<?php

namespace App\Listeners;

use App\Domain\Commerce\Events\PaymentCompleted;
use App\Models\Payment;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Billing engine M1 — the payment → subscription loop.
 *
 * Subscribed to the PaymentCompleted domain event (registered in
 * App\Providers\EventServiceProvider, dispatched by
 * App\Jobs\RelayOutboxEventsJob off the `payment.completed` outbox row, so
 * this runs asynchronously off the relay, never in the webhook request).
 *
 * Every gateway now funnels a completed plan payment through
 * RecordPaymentCompletionCommand → PaymentCompleted, so this is the ONE
 * place a paid plan turns into an active Subscription.
 * SubscriptionService::activateFromPayment() is idempotent by payment_id,
 * so a re-delivered event / double webhook produces exactly one
 * subscription.
 *
 * Queued (ShouldQueue). handle() rethrows so a transient failure is retried
 * (queue backoff in production, the relay's per-row attempt counter when run
 * synchronously); failed() is the last-resort net once retries are
 * exhausted — a paid plan that never got its subscription must be visible.
 */
class ActivateSubscriptionOnPaymentCompleted implements ShouldQueue
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(PaymentCompleted $event): void
    {
        $payment = Payment::find($event->paymentId);

        // Not every completed payment is a plan purchase (e.g. a future
        // one-off charge with no plan_id) — nothing to activate.
        if ($payment === null || $payment->plan_id === null) {
            return;
        }

        try {
            app(SubscriptionService::class)->activateFromPayment($payment);
        } catch (Throwable $e) {
            Log::channel('errors')->error('ActivateSubscriptionOnPaymentCompleted: failed to activate a subscription from a completed payment.', [
                'listener' => self::class,
                'payment_id' => $event->paymentId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }

    public function failed(PaymentCompleted $event, ?Throwable $e): void
    {
        Log::channel('errors')->error('ActivateSubscriptionOnPaymentCompleted failed permanently — a paid plan did not receive its subscription.', [
            'listener' => self::class,
            'payment_id' => $event->paymentId,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }
}
