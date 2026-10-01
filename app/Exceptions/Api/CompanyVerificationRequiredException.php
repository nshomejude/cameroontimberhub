<?php

namespace App\Exceptions\Api;

use App\Models\Company;

/**
 * 403 `company_verification_required`: the caller's company may SEE buyer
 * requests while pending verification but may not act on them (quote,
 * express interest, move a lead, contact the buyer) until it is Verified —
 * {@see Company::canRespondToBuyers()}.
 */
class CompanyVerificationRequiredException extends ApiException
{
    public function __construct()
    {
        parent::__construct(403, Company::VERIFICATION_REQUIRED_CODE, Company::VERIFICATION_REQUIRED_MESSAGE);
    }

    /** Throw unless the company may respond to buyers. */
    public static function unless(?Company $company): void
    {
        if ($company === null || ! $company->canRespondToBuyers()) {
            throw new self;
        }
    }
}
