<?php

namespace App\Actions\Verification;

use App\Enums\BadgeType;
use App\Events\BadgeIssued;
use App\Models\Company;
use App\Models\User;
use App\Models\VerificationBadge;
use App\Models\VerificationRequest;
use App\Services\BadgeService;

class IssueBadge
{
    public function __construct(private readonly BadgeService $badges) {}

    public function execute(Company $company, BadgeType $type, ?User $actor, ?VerificationRequest $request = null): ?VerificationBadge
    {
        $badge = $this->badges->issue($company, $type, $actor, $request);

        if ($badge) {
            BadgeIssued::dispatch($badge, $actor);
        }

        return $badge;
    }
}
