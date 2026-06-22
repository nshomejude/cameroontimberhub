<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The single mutation point for `companies.status` (spec §1.1). Filament
 * actions and the onboarding flow both call this; illegal transitions throw,
 * reason-required transitions are enforced, and every change is activity-logged.
 */
class CompanyStatusService
{
    /** @var array<string, list<string>> legal target states per source state */
    public const TRANSITIONS = [
        'draft' => ['pending'],
        'pending' => ['verified', 'rejected', 'draft'],
        'verified' => ['suspended', 'pending', 'archived'],
        'suspended' => ['verified', 'archived'],
        'rejected' => ['pending', 'archived'],
        'archived' => [],
    ];

    public function transition(Company $company, CompanyStatus $to, ?User $actor = null, ?string $reason = null): Company
    {
        $from = $company->status;

        if ($from === $to) {
            return $company;
        }

        $isArchive = $to === CompanyStatus::Archived && $from !== CompanyStatus::Archived;

        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true) && ! $isArchive) {
            throw new RuntimeException("Illegal company status transition: {$from->value} -> {$to->value}");
        }

        if ($this->requiresReason($from, $to) && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this status change.',
            ]);
        }

        $company->status = $to;

        if ($to === CompanyStatus::Verified) {
            $company->verified_at = now();
        }

        $company->save();

        $logger = activity('company-status')->performedOn($company);
        if ($actor) {
            $logger->causedBy($actor);
        }
        $logger->event('status_changed')
            ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason])
            ->log("Company status changed from {$from->value} to {$to->value}");

        return $company;
    }

    protected function requiresReason(CompanyStatus $from, CompanyStatus $to): bool
    {
        return in_array($to, [CompanyStatus::Rejected, CompanyStatus::Suspended], true)
            || ($from === CompanyStatus::Pending && $to === CompanyStatus::Draft)      // return to draft
            || ($from === CompanyStatus::Suspended && $to === CompanyStatus::Verified); // reinstate
    }

    // ---- Convenience wrappers used by Filament actions / onboarding ------

    public function submit(Company $c, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Pending, $actor);
    }

    public function approve(Company $c, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Verified, $actor);
    }

    public function reject(Company $c, string $reason, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Rejected, $actor, $reason);
    }

    public function returnToDraft(Company $c, string $reason, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Draft, $actor, $reason);
    }

    public function suspend(Company $c, string $reason, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Suspended, $actor, $reason);
    }

    public function reinstate(Company $c, string $reason, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Verified, $actor, $reason);
    }

    public function archive(Company $c, ?User $actor = null): Company
    {
        return $this->transition($c, CompanyStatus::Archived, $actor);
    }
}
