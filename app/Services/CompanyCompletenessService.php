<?php

namespace App\Services;

use App\Models\Company;

/**
 * Computes a company's profile completeness (spec §1.2). The percentage is
 * advisory; the explicit boolean requirements below are what gate public
 * visibility (mirrored by Company::scopePubliclyVisible).
 */
class CompanyCompletenessService
{
    public const MIN_DESCRIPTION_LENGTH = 50;

    /** Full completeness (incl. active badge) — drives the public-visibility gate. */
    public function score(Company $company): CompletenessResult
    {
        return $this->result($this->checks($company, includeBadge: true));
    }

    /**
     * Profile readiness for submitting draft/rejected -> pending. Excludes the
     * active-badge requirement, which is only issued during/after review.
     */
    public function readyForSubmission(Company $company): CompletenessResult
    {
        return $this->result($this->checks($company, includeBadge: false));
    }

    /** @return array<string, bool> */
    protected function checks(Company $company, bool $includeBadge): array
    {
        $checks = [
            'A legal name' => filled($company->legal_name),
            'A description (min '.self::MIN_DESCRIPTION_LENGTH.' chars)' =>
                filled($company->description) && mb_strlen(strip_tags((string) $company->description)) >= self::MIN_DESCRIPTION_LENGTH,
            'A region' => filled($company->region),
            'A logo' => filled($company->logo_path),
            'At least one contact' => $company->contacts()->exists(),
            'At least one species' => $company->species()->exists(),
        ];

        if ($includeBadge) {
            $checks['An active verification badge'] = $company->activeBadges()->exists();
        }

        return $checks;
    }

    protected function result(array $checks): CompletenessResult
    {
        $missing = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
        $percentage = (int) round(count(array_filter($checks)) / max(1, count($checks)) * 100);

        return new CompletenessResult($percentage, array_values($missing));
    }
}
