<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DocumentType;
use App\Services\CompanyCompletenessService;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's OWN company's verification status, over token auth — the API
 * counterpart of the exporter panel's `VerificationStatusWidget`/
 * `OnboardingChecklist` (`CompanyStatus`, `verificationBadges`,
 * `profile_completion`), reshaped for the mobile app.
 *
 * Sits behind `api.supplier` (`EnsureApiSupplier`): only a user who belongs
 * to at least one company reaches this controller. "The caller's company" is
 * resolved as their first `company_user` membership, the same convention
 * `CompanyDocumentController`/`SupplierApiScope` already use.
 *
 * Real data only, from the real verification models — no invented
 * aggregates:
 *  - `status`: `Company::status` (`CompanyStatus`), the same enum the
 *    exporter widget renders.
 *  - `badges`: active (`BadgeStatus::Active`, unexpired) `VerificationBadge`
 *    rows — the exact `activeBadges()` scoping `VerificationStatusWidget`
 *    already counts.
 *  - `missing_documents`: required, active `DocumentType`s (`is_required`,
 *    `is_active`) this company has no APPROVED `CompanyDocument` for yet.
 *    Company's own `documents` relation is `CompanyDocument` (not the
 *    generic `HasDocuments`/`Document` store that trait wraps — Company
 *    does not use that trait), so this mirrors that trait's spirit
 *    (`documentComplianceState()`'s 'none'/'ok' distinction) against the
 *    model this app actually uses for company compliance paperwork.
 *  - `next_step`: a single human-readable string derived from the same
 *    state, in priority order (missing required docs -> open verification
 *    request -> nothing left, already verified/pending/etc).
 */
class CompanyVerificationController extends Controller
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly CompanyCompletenessService $completeness,
    ) {}

    /**
     * Submit the caller's own company for verification review — the API
     * counterpart of `EditCompany`'s "Submit for review" header action.
     * Same gate (`CompanyCompletenessService::readyForSubmission()`), same
     * status guard (only Draft/Rejected may submit — `VerificationService::submit()`
     * itself no-ops/returns the existing open request for anything else, so
     * this never double-submits), same underlying service call — no second
     * submission path.
     */
    public function submit(Request $request): JsonResponse
    {
        $company = $request->user()->companies()->first();

        if ($company === null) {
            return response()->json(['message' => 'This account has no company.'], 404);
        }

        $readiness = $this->completeness->readyForSubmission($company);

        if (! $readiness->isComplete()) {
            return response()->json([
                'error' => [
                    'code' => 'profile_incomplete',
                    'message' => 'Your profile is not yet ready for submission.',
                    'details' => ['missing' => $readiness->missing],
                ],
            ], 422);
        }

        $verificationRequest = $this->verification->submit($company, [], $request->user());

        return response()->json([
            'data' => [
                'status' => $verificationRequest->status->value,
                'submitted_at' => $verificationRequest->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->user()->companies()->first();

        if ($company === null) {
            return response()->json([
                'message' => 'This account has no company.',
            ], 404);
        }

        $company->loadMissing(['verificationBadges', 'documents.documentType', 'verificationRequests']);

        $badges = $company->verificationBadges
            ->filter(fn ($badge) => $badge->status === \App\Enums\BadgeStatus::Active
                && ($badge->valid_until === null || $badge->valid_until->isFuture()))
            ->values()
            ->map(fn ($badge) => [
                'type' => $badge->badge_type->value,
                'label' => $badge->badge_type->label(),
                'issued_at' => $badge->issued_at?->toIso8601String(),
                'valid_until' => $badge->valid_until?->toDateString(),
            ])
            ->all();

        $missingDocuments = $this->missingDocuments($company);

        return response()->json([
            'data' => [
                'status' => $company->status->value,
                'status_label' => $company->status->label(),
                'badges' => $badges,
                'missing_documents' => $missingDocuments,
                'next_step' => $this->nextStep($company, $missingDocuments),
            ],
        ]);
    }

    /** @return list<array{key: string, label: string}> */
    private function missingDocuments(Company $company): array
    {
        $approvedTypeIds = $company->documents
            ->filter(fn ($document) => $document->status === DocumentStatus::Approved && ! $document->isExpired())
            ->pluck('document_type_id')
            ->all();

        return DocumentType::query()
            ->active()
            ->where('is_required', true)
            ->whereNotIn('id', $approvedTypeIds)
            ->get(['key', 'name'])
            ->map(fn (DocumentType $type) => [
                'key' => $type->key,
                'label' => $type->name,
            ])
            ->values()
            ->all();
    }

    /** @param list<array{key: string, label: string}> $missingDocuments */
    private function nextStep(Company $company, array $missingDocuments): string
    {
        if ($missingDocuments !== []) {
            return 'Upload the missing required documents: '.implode(', ', array_column($missingDocuments, 'label')).'.';
        }

        $openRequest = $company->verificationRequests->firstWhere(
            fn ($request) => in_array($request->status->value, ['pending', 'in_review'], true),
        );

        if ($openRequest !== null) {
            return 'Your verification request is under review.';
        }

        return match ($company->status->value) {
            'verified' => 'No action needed — your company is verified.',
            'rejected' => 'Your last verification request was rejected. Submit a new one once the issues are addressed.',
            'suspended' => 'Your company is suspended. Contact support to resolve this.',
            default => 'Submit a verification request once your documents are ready.',
        };
    }
}
