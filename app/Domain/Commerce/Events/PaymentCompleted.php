<?php

namespace App\Domain\Commerce\Events;

use App\Support\Events\DomainEvent;

/**
 * A Payment attempt completed successfully (App\Models\Payment::
 * markCompleted() — called by every gateway's webhook handler once the
 * provider confirms funds captured). High-value for "API as a product"
 * webhooks — finance/accounting tooling wants to know the moment a payment
 * completes, regardless of which of the 4 gateways processed it.
 */
class PaymentCompleted implements DomainEvent
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $companyId,
        public readonly string $provider,
        public readonly string $amount,
        public readonly string $currency,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            paymentId: (int) $payload['payment_id'],
            companyId: (int) $payload['company_id'],
            provider: (string) $payload['provider'],
            amount: (string) $payload['amount'],
            currency: (string) $payload['currency'],
        );
    }

    public function aggregateType(): string
    {
        return 'Payment';
    }

    public function aggregateId(): int|string
    {
        return $this->paymentId;
    }

    public function eventType(): string
    {
        return 'payment.completed';
    }

    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'company_id' => $this->companyId,
            'provider' => $this->provider,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
