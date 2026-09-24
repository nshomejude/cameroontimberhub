<?php

namespace App\Listeners;

use App\Domain\Commerce\Events\PaymentCompleted;
use App\Models\Payment;
use App\Services\Referrals\ReferralService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Referral programme — 10% (configurable) of a referred company's FIRST
 * completed subscription payment goes to its referrer, once. Hangs off the
 * same outbox-relayed PaymentCompleted event as subscription activation /
 * invoicing; idempotent by payment_id (unique on referral_earnings).
 */
class AwardReferralCommissionOnPaymentCompleted implements ShouldQueue
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
            app(ReferralService::class)->awardForPayment($payment);
        } catch (Throwable $e) {
            Log::channel('errors')->error('AwardReferralCommissionOnPaymentCompleted: failed to award a referral commission.', [
                'listener' => self::class,
                'payment_id' => $event->paymentId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }
}
