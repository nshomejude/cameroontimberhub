<?php

namespace App\Domain\Identity\Commands;

use App\Support\Bus\Command;

/**
 * Approve a company's verification request. Thin DTO — carries only the
 * identifiers the handler needs to look up the real Eloquent models; it does
 * not duplicate any of VerificationService's state-machine or badge-issuance
 * logic (architecture plan, Phase 4: Identity & Access).
 */
final class ApproveVerificationCommand implements Command
{
    public function __construct(
        public readonly int $verificationRequestId,
        public readonly int $actingUserId,
    ) {}
}
