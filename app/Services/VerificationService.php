<?php

namespace App\Services;

use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\DocumentStatus;
use App\Enums\VerificationRequestStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The verification state machine (spec §5): request-level and per-document
 * transitions. Each mutation is transactional and activity-logged. Approval
 * issues the requested badges (those whose backing docs are satisfied) and
 * verifies the company.
 */
class VerificationService
{
    public function __construct(
        private readonly BadgeService $badges,
        private readonly CompanyStatusService $companyStatus,
    ) {}

    public function submit(Company $company, array $requestedBadges = [], ?User $actor = null): VerificationRequest
    {
        if ($open = $company->verificationRequests()->open()->first()) {
            return $open;
        }

        return DB::transaction(function () use ($company, $requestedBadges, $actor) {
            $request = $company->verificationRequests()->create([
                'type' => 'standard',
                'status' => VerificationRequestStatus::Pending,
                'requested_badges' => $requestedBadges ?: [BadgeType::VerifiedCompany->value],
            ]);

            if (in_array($company->status, [CompanyStatus::Draft, CompanyStatus::Rejected], true)) {
                $this->companyStatus->submit($company, $actor);
            }

            activity('compliance')->performedOn($request)->causedBy($actor)->event('verification_submitted')->log('Verification submitted');

            return $request;
        });
    }

    public function startReview(VerificationRequest $request, User $actor): VerificationRequest
    {
        $this->assertStatus($request, [VerificationRequestStatus::Pending]);

        $request->update([
            'status' => VerificationRequestStatus::InReview,
            'assigned_to' => $actor->getKey(),
        ]);

        activity('compliance')->performedOn($request)->causedBy($actor)->event('verification_review_started')->log('Review started');

        return $request;
    }

    public function approveDocument(CompanyDocument $document, User $actor, ?string $expiryDate = null): void
    {
        if ($document->documentType?->requires_expiry && blank($expiryDate) && blank($document->expiry_date)) {
            throw ValidationException::withMessages(['expiry_date' => 'This document type requires an expiry date.']);
        }

        $document->update([
            'status' => DocumentStatus::Approved,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'expiry_date' => $expiryDate ?: $document->expiry_date,
            'rejection_reason' => null,
        ]);

        activity('compliance')->performedOn($document)->causedBy($actor)->event('document_approved')->log('Document approved');
    }

    public function rejectDocument(CompanyDocument $document, string $reason, User $actor): void
    {
        $document->update([
            'status' => DocumentStatus::Rejected,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        activity('compliance')->performedOn($document)->causedBy($actor)->event('document_rejected')->log('Document rejected');
    }

    public function requestCorrection(CompanyDocument $document, string $instructions, User $actor): void
    {
        $document->update([
            'status' => DocumentStatus::NeedsCorrection,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => $instructions,
        ]);

        activity('compliance')->performedOn($document)->causedBy($actor)->event('document_needs_correction')->log('Document needs correction');
    }

    /** @return array{issued: list<string>, skipped: list<string>} */
    public function approve(VerificationRequest $request, User $actor): array
    {
        $this->assertStatus($request, [VerificationRequestStatus::Pending, VerificationRequestStatus::InReview]);

        return DB::transaction(function () use ($request, $actor) {
            $company = $request->company;
            $issued = [];
            $skipped = [];

            foreach ((array) $request->requested_badges as $value) {
                $type = BadgeType::tryFrom($value);
                if ($type && $this->badges->issue($company, $type, $actor, $request)) {
                    $issued[] = $value;
                } else {
                    $skipped[] = $value;
                }
            }

            $request->update([
                'status' => VerificationRequestStatus::Approved,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'document_snapshot' => $this->snapshot($company),
                'decision_notes' => $skipped ? ('Not issued (requirements unmet): '.implode(', ', $skipped)) : null,
            ]);

            if ($company->status !== CompanyStatus::Verified) {
                $this->companyStatus->approve($company, $actor);
            }

            $company->forceFill([
                'verified_at' => now(),
                'verification_expires_at' => $company->activeBadges()->min('valid_until'),
            ])->save();

            activity('compliance')->performedOn($request)->causedBy($actor)->event('verification_approved')
                ->withProperties(['issued' => $issued, 'skipped' => $skipped])->log('Verification approved');

            return ['issued' => $issued, 'skipped' => $skipped];
        });
    }

    public function reject(VerificationRequest $request, string $notes, User $actor): void
    {
        $this->assertStatus($request, [VerificationRequestStatus::Pending, VerificationRequestStatus::InReview]);

        $company = $request->company;

        $request->update([
            'status' => VerificationRequestStatus::Rejected,
            'decided_by' => $actor->getKey(),
            'decided_at' => now(),
            'decision_notes' => $notes,
            'document_snapshot' => $this->snapshot($company),
        ]);

        if ($company->status === CompanyStatus::Pending) {
            $this->companyStatus->reject($company, $notes, $actor);
        }

        activity('compliance')->performedOn($request)->causedBy($actor)->event('verification_rejected')->log('Verification rejected');
    }

    /** @return list<array{id:int, document_type_id:int, status:string}> */
    protected function snapshot(Company $company): array
    {
        return $company->documents()->get(['id', 'document_type_id', 'status'])
            ->map(fn (CompanyDocument $d) => [
                'id' => $d->id,
                'document_type_id' => $d->document_type_id,
                'status' => $d->status->value,
            ])->all();
    }

    protected function assertStatus(VerificationRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw new RuntimeException('Illegal verification request transition from '.$request->status->value);
        }
    }
}
