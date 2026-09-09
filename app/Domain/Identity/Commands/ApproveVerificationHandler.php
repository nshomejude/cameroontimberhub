<?php

namespace App\Domain\Identity\Commands;

use App\Domain\Identity\Events\CompanyVerified;
use App\Enums\CompanyStatus;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\VerificationService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing verification-approval logic. All the actual
 * state machine, badge-issuance and company-status transition logic lives in
 * VerificationService::approve() — this handler is not a rewrite, it just
 * gives that behaviour a Command/Bus entry point and records the
 * `company.verified` domain event when the approval is the one that actually
 * moves the company into the terminal CompanyStatus::Verified stage.
 *
 * CommandBus::dispatch() already wraps this handler's call in DB::transaction
 * (see App\Support\Bus\CommandBus), so recordOutboxEvent() below joins that
 * same transaction — VerificationService::approve()'s own nested transaction
 * commits/rolls back together with it, and the outbox write can never
 * diverge from the state change.
 */
final class ApproveVerificationHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function __construct(private readonly VerificationService $verification) {}

    /** @return array{issued: list<string>, skipped: list<string>} */
    public function handle(Command $command): array
    {
        /** @var ApproveVerificationCommand $command */
        $request = VerificationRequest::findOrFail($command->verificationRequestId);
        $actor = User::findOrFail($command->actingUserId);

        $company = $request->company;
        $wasAlreadyVerified = $company->status === CompanyStatus::Verified;

        $result = $this->verification->approve($request, $actor);

        $company = $company->fresh();

        if (! $wasAlreadyVerified && $company->status === CompanyStatus::Verified) {
            $this->recordOutboxEvent(new CompanyVerified(
                companyId: $company->getKey(),
                verificationRequestId: $request->getKey(),
            ));
        }

        return $result;
    }
}
