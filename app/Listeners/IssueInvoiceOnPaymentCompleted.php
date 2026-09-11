<?php

namespace App\Listeners;

use App\Domain\Commerce\Events\PaymentCompleted;
use App\Models\Payment;
use App\Services\Billing\InvoiceIssuer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Billing engine M4 — issue the platform's immutable Invoice for a completed
 * plan Payment.
 *
 * Subscribed to the PaymentCompleted domain event (EventServiceProvider),
 * dispatched by RelayOutboxEventsJob off the `payment.completed` outbox row,
 * alongside ActivateSubscriptionOnPaymentCompleted / IssueReceiptOnPaymentCompleted.
 *
 * Idempotent: InvoiceIssuer::issueForPayment() is keyed on `payment_id`, so a
 * re-delivered event / double webhook produces exactly one invoice. Queued
 * (ShouldQueue), tries=3; handle() rethrows for retry, failed() is the
 * last-resort visibility net.
 */
class IssueInvoiceOnPaymentCompleted implements ShouldQueue
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

        if ($payment === null || $payment->plan_id === null) {
            return;
        }

        try {
            app(InvoiceIssuer::class)->issueForPayment($payment);
        } catch (Throwable $e) {
            Log::channel('errors')->error('IssueInvoiceOnPaymentCompleted: failed to issue an invoice for a completed payment.', [
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
        Log::channel('errors')->error('IssueInvoiceOnPaymentCompleted failed permanently — a completed payment did not receive its invoice.', [
            'listener' => self::class,
            'payment_id' => $event->paymentId,
            'exception' => $e?->getMessage(),
        ]);
    }
}
