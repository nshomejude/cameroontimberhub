<?php

namespace App\Enums;

/**
 * Batch F (§2.6) — the carbon project registry lifecycle. Registry only: this
 * is the accreditation/verification state of the project itself, NOT any
 * credit issuance or trading state (§2.7, out of scope).
 *
 * Transitions are an explicit allow-list (mirroring
 * App\Services\VerificationFlowService's discipline) — anything not listed
 * in canTransitionTo() is illegal.
 */
enum CarbonRegistryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Registered = 'registered';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __('messages.enums.carbon_registry_status.'.$this->value);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Registered, self::Rejected, self::Submitted],
            self::Registered => [self::Active, self::Suspended],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
            self::Rejected => [self::Submitted],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Projects the public verification page is allowed to disclose. */
    public function isPubliclyVerifiable(): bool
    {
        return $this === self::Registered || $this === self::Active;
    }
}
