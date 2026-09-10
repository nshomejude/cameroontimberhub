<?php

namespace App\Listeners;

use App\Domain\Commerce\Events\PaymentCompleted;
use App\Models\Payment;
use App\Models\Receipt;
use App\Services\OrderReferenceGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Billing engine M11 — issue the platform's verifiable Receipt for a
 * completed plan Payment.
 *
 * Subscribed to the PaymentCompleted domain event (EventServiceProvider),
 * dispatched by RelayOutboxEventsJob off the `payment.completed` outbox row —
 * so every gateway path produces a receipt, and it runs off the relay, never
 * in the webhook request.
 *
 * Idempotent: one live receipt per `payment_id`, so a re-delivered event /
 * double webhook produces exactly one. The receipt joins the same
 * hash-chain (ChainsIntegrity) the order receipts use.
 *
 * Queued (ShouldQueue). handle() rethrows so a transient failure is retried;
 * failed() is the last-resort visibility net.
 */
class IssueReceiptOnPaymentCompleted implements ShouldQueue
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

        if (Receipt::where('payment_id', $payment->id)->whereNull('voided_at')->exists()) {
            return;
        }

        try {
            $references = app(OrderReferenceGenerator::class);

            Receipt::create([
                'payment_id' => $payment->id,
                'receipt_number' => $references->receipt(),
                'verification_token' => $references->verificationToken(),
                'issued_at' => now(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ]);
        } catch (Throwable $e) {
            Log::channel('errors')->error('IssueReceiptOnPaymentCompleted: failed to issue a receipt for a completed payment.', [
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
        Log::channel('errors')->error('IssueReceiptOnPaymentCompleted failed permanently — a completed payment did not receive its receipt.', [
            'listener' => self::class,
            'payment_id' => $event->paymentId,
            'exception' => $e?->getMessage(),
        ]);
    }
}
