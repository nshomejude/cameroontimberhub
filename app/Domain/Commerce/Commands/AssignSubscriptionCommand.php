<?php

namespace App\Domain\Commerce\Commands;

use App\Support\Bus\Command;

/**
 * Assign a Plan to a Company (manual, no payment gateway involved — see
 * App\Services\SubscriptionService::assign()). Thin DTO — carries only the
 * identifiers the handler needs to look up the real Eloquent models; it does
 * not duplicate any of SubscriptionService's business rules.
 */
final class AssignSubscriptionCommand implements Command
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $planId,
        public readonly ?int $actingUserId = null,
        public readonly ?string $notes = null,
    ) {}
}
