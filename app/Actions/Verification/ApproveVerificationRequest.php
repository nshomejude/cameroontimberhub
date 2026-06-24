<?php

namespace App\Actions\Verification;

use App\Events\BadgeIssued;
use App\Events\CompanyVerified;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\VerificationService;

class ApproveVerificationRequest
{
    public function __construct(private readonly VerificationService $verification) {}

    /** @return array{issued: list<string>, skipped: list<string>} */
    public function execute(VerificationRequest $request, User $actor): array
    {
        $result = $this->verification->approve($request, $actor);

        $company = $request->fresh()->company;

        if (! empty($result['issued'])) {
            CompanyVerified::dispatch($company, $actor);

            foreach ($company->activeBadges()->get() as $badge) {
                BadgeIssued::dispatch($badge, $actor);
            }
        }

        return $result;
    }
}
