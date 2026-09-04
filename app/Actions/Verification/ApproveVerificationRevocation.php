<?php

namespace App\Actions\Verification;

use App\Enums\BadgeStatus;
use App\Models\User;
use App\Models\VerificationRevocationRequest;
use App\Services\BadgeService;
use Illuminate\Validation\ValidationException;

/**
 * Second step of the two-person revocation control (blueprint §89). Enforces
 * that the approving user is NOT the same person who requested the
 * revocation, then revokes every active VerificationBadge on the company.
 */
class ApproveVerificationRevocation
{
    public function __construct(private readonly BadgeService $badges) {}

    public function execute(VerificationRevocationRequest $request, User $approvedBy): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'This revocation request has already been decided.']);
        }

        if ((int) $request->requested_by === (int) $approvedBy->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'The revocation must be approved by a different staff member than the one who requested it.']);
        }

        $company = $request->company;

        $company->verificationBadges()
            ->where('status', BadgeStatus::Active->value)
            ->get()
            ->each(fn ($badge) => $this->badges->revoke($badge, $request->reason, $approvedBy));

        $request->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->getKey(),
        ]);
    }
}
