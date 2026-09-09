<?php

namespace App\Actions\ApiKeys;

use App\Enums\CompanyUserRole;
use App\Models\ApiKeyIssuanceRequest;
use App\Models\ApiKeyMeta;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Second step of the two-person API-key-issuance control (mirrors
 * App\Actions\Ai\ApproveAiApiKeyChange exactly). Requires ALL of:
 *  - the request is still pending and not expired
 *  - the approving user is not the same person who requested the issuance
 *  - the approver supplies the correct one-time invite token
 *  - the approver has a recent (within TwoFactorStepUp's window) confirmed
 *    TOTP code
 * Only then is a REAL Sanctum personal access token created (with the
 * requested abilities), scoped to the company via App\Models\ApiKeyMeta,
 * and its plaintext value returned. Like any Sanctum token, this is the
 * only time the plaintext is ever available.
 */
class ApproveApiKeyIssuance
{
    public function __construct(private readonly TwoFactorStepUp $stepUp) {}

    /** @return array{plain_text_token: string, meta: ApiKeyMeta} */
    public function execute(ApiKeyIssuanceRequest $issuanceRequest, User $approvedBy, string $inviteToken, Request $httpRequest): array
    {
        if (! $issuanceRequest->isPending()) {
            throw ValidationException::withMessages(['status' => 'This API key issuance request has already been decided or has expired.']);
        }

        if ((int) $issuanceRequest->requested_by === (int) $approvedBy->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'The key issuance must be approved by a different administrator than the one who requested it.']);
        }

        if (! $issuanceRequest->inviteTokenMatches($inviteToken)) {
            throw ValidationException::withMessages(['invite_token' => 'That invite token is incorrect.']);
        }

        if (! $this->stepUp->isRecentlyVerified($httpRequest)) {
            throw ValidationException::withMessages(['two_factor' => 'Please re-confirm your two-factor code before approving an API key issuance.']);
        }

        $company = $issuanceRequest->company;

        // Sanctum tokens are only ever issued against an Authenticatable
        // (User) tokenable, not the Company itself — see App\Models\ApiKeyMeta
        // doc block. The company's designated owner is the natural holder of
        // a "company-owned" key; fall back to any member if no owner pivot
        // row exists yet.
        $tokenable = $company->users()->wherePivot('role', CompanyUserRole::Owner)->first()
            ?? $company->users()->first();

        if (! $tokenable) {
            throw ValidationException::withMessages(['company_id' => 'This company has no user to hold the API key.']);
        }

        /** @var NewAccessToken $newToken */
        $newToken = $tokenable->createToken($issuanceRequest->requested_name, $issuanceRequest->requested_abilities);

        $meta = ApiKeyMeta::create([
            'personal_access_token_id' => $newToken->accessToken->getKey(),
            'company_id' => $company->getKey(),
            'rate_limit_tier' => 'standard',
            'requested_by' => $issuanceRequest->requested_by,
            'approved_by' => $approvedBy->getKey(),
        ]);

        $issuanceRequest->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->getKey(),
            'decided_at' => now(),
        ]);

        return ['plain_text_token' => $newToken->plainTextToken, 'meta' => $meta];
    }
}
