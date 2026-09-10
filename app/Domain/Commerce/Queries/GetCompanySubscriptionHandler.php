<?php

namespace App\Domain\Commerce\Queries;

use App\Models\Company;
use App\Models\Subscription;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;

/**
 * Byte-identical to `SubscriptionStatus::getActiveSubscription()`'s former
 * inline read: `$company->subscriptions()->active()->latest()->first()`.
 * The `active()` scope on Subscription holds the status condition — not
 * reimplemented here.
 */
final class GetCompanySubscriptionHandler implements HandlesQuery
{
    public function handle(Query $query): ?Subscription
    {
        /** @var GetCompanySubscriptionQuery $query */
        $company = Company::findOrFail($query->companyId);

        return $company->subscriptions()->active()->latest()->first();
    }
}
