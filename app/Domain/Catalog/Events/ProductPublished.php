<?php

namespace App\Domain\Catalog\Events;

use App\Support\Events\DomainEvent;

/**
 * A Product listing actually went live (its status transitioned to
 * Active — see App\Observers\ProductObserver::maybeCreateLot(), the single
 * place this transition is detected, since that is also the moment a
 * genuine tradeable listing gets its linked TimberLot). Useful for webhook
 * subscribers wanting to know when new inventory appears (e.g. a
 * buyer-side integration).
 */
class ProductPublished implements DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly int $companyId,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            productId: (int) $payload['product_id'],
            companyId: (int) $payload['company_id'],
        );
    }

    public function aggregateType(): string
    {
        return 'Product';
    }

    public function aggregateId(): int|string
    {
        return $this->productId;
    }

    public function eventType(): string
    {
        return 'product.published';
    }

    public function payload(): array
    {
        return [
            'product_id' => $this->productId,
            'company_id' => $this->companyId,
        ];
    }
}
