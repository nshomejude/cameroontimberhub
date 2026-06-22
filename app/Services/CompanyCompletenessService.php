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

    public function score(Company $company): CompletenessResult
    {
        $checks = [
            'A legal name' => filled($company->legal_name),
            'A description (min '.self::MIN_DESCRIPTION_LENGTH.' chars)' =>
                filled($company->description) && mb_strlen(strip_tags((string) $company->description)) >= self::MIN_DESCRIPTION_LENGTH,
            'A region' => filled($company->region),
            'A logo' => filled($company->logo_path),
            'At least one contact' => $company->contacts()->exists(),
            'At least one species' => $company->species()->exists(),
            'An active verification badge' => $company->activeBadges()->exists(),
        ];

        $missing = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
        $percentage = (int) round(count(array_filter($checks)) / count($checks) * 100);

        return new CompletenessResult($percentage, array_values($missing));
    }
}
