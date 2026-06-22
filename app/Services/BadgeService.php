<?php

namespace App\Services;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Models\VerificationBadge;
use App\Models\VerificationRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Issues/revokes verification badges. A badge type is only issuable when its
 * backing document_types (config compliance.badge_requirements) are approved
 * and unexpired. One active badge per type per company is enforced here and by
 * the partial unique index.
 */
class BadgeService
{
    /** @return list<string> document_type keys backing this badge */
    public function requirements(BadgeType $type): array
    {
        return (array) config('compliance.badge_requirements.'.$type->value, []);
    }

    public function canIssue(Company $company, BadgeType $type): bool
    {
        if ($type === BadgeType::PremiumMember) {
            return false; // plan-gated, not document-backed
        }

        $required = $this->requirements($type);
        if ($required === []) {
            return false;
        }

        foreach ($required as $key) {
            $document = $this->approvedDocument($company, $key);
            if (! $document) {
                return false;
            }
            if ($type === BadgeType::SigifRegistered && blank(data_get($document->sigif_fields, 'registration_number'))) {
                return false;
            }
        }

        return true;
    }

    public function issue(Company $company, BadgeType $type, ?User $issuer, ?VerificationRequest $request = null): ?VerificationBadge
    {
        if (! $this->canIssue($company, $type)) {
            return null;
        }

        // One active badge per type: revoke any existing active of this type first.
        $company->verificationBadges()
            ->where('badge_type', $type->value)
            ->where('status', BadgeStatus::Active->value)
            ->update([
                'status' => BadgeStatus::Revoked->value,
                'revoked_at' => now(),
                'revoked_reason' => 'Reissued',
                'revoked_by' => $issuer?->getKey(),
            ]);

        $backing = $this->backingDocuments($company, $type);

        $badge = $company->verificationBadges()->create([
            'badge_type' => $type,
            'status' => BadgeStatus::Active,
            'issued_at' => now(),
            'valid_until' => $backing->pluck('expiry_date')->filter()->min(),
            'verified_by' => $issuer?->getKey(),
            'verification_request_id' => $request?->getKey(),
            'supporting_document_id' => $backing->first()?->getKey(),
            'is_public' => true,
            'reference_code' => $this->uniqueReference(),
        ]);

        activity('compliance')->performedOn($badge)->causedBy($issuer)->event('badge_issued')->log("Badge {$type->value} issued");

        return $badge;
    }

    public function revoke(VerificationBadge $badge, string $reason, ?User $actor): void
    {
        $badge->update([
            'status' => BadgeStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'revoked_by' => $actor?->getKey(),
        ]);

        activity('compliance')->performedOn($badge)->causedBy($actor)->event('badge_revoked')->withProperties(['reason' => $reason])->log('Badge revoked');
    }

    protected function approvedDocument(Company $company, string $typeKey): ?CompanyDocument
    {
        return $company->documents()
            ->whereHas('documentType', fn ($q) => $q->where('key', $typeKey))
            ->approved()
            ->latest()
            ->first();
    }

    /** @return Collection<int, CompanyDocument> */
    protected function backingDocuments(Company $company, BadgeType $type): Collection
    {
        return collect($this->requirements($type))
            ->map(fn (string $key) => $this->approvedDocument($company, $key))
            ->filter()
            ->values();
    }

    protected function uniqueReference(): string
    {
        do {
            $reference = 'CTH-'.strtoupper(Str::random(8));
        } while (VerificationBadge::where('reference_code', $reference)->exists());

        return $reference;
    }
}
