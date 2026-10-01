<?php

namespace App\Domain\Commerce\Commands;

use App\Domain\Commerce\Events\PaymentCompleted;
use App\Models\Payment;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing payment-completion logic. All the actual state
 * change lives in Payment::markCompleted() — this handler is not a rewrite,
 * it just gives that behaviour a Command/Bus entry point and records the
 * PaymentCompleted domain event to the outbox inside the same transaction
 * CommandBus::dispatch() already opens.
 *
 * Wired through ONE gateway (Stripe, see App\Services\Payments\
 * StripeGateway::handleWebhook()) as the proof-of-pattern for this batch —
 * see that class's doc block for why the other three gateways' webhook call
 * sites were left calling Payment::markCompleted() directly rather than
 * rewired here in the same pass.
 */
final class RecordPaymentCompletionHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function handle(Command $command): Payment
    {
        /** @var RecordPaymentCompletionCommand $command */
        $payment = Payment::findOrFail($command->paymentId);

        // Only the call that actually completes the payment records the
        // event: a replayed webhook / double return leg is a no-op here, not
        // just downstream.
        if (! $payment->markCompleted($command->providerReference)) {
            return $payment;
        }

        $this->recordOutboxEvent(new PaymentCompleted(
            paymentId: $payment->getKey(),
            companyId: $payment->company_id,
            provider: $payment->provider instanceof \BackedEnum ? $payment->provider->value : (string) $payment->provider,
            amount: (string) $payment->amount,
            currency: $payment->currency,
        ));

        return $payment;
    }
}
