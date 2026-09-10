<?php

namespace App\Domain\Commerce\Queries;

use App\Support\Bus\Query;

/**
 * A company's current active subscription (or null) — backs the exporter
 * panel's SubscriptionStatus page and any future billing API.
 *
 * "Current" means the latest row with status = active, exactly as
 * `SubscriptionStatus::getActiveSubscription()` derived it inline
 * (`subscriptions()->active()->latest()->first()`).
 */
final class GetCompanySubscriptionQuery implements Query
{
    public function __construct(
        public readonly int $companyId,
    ) {}
}
