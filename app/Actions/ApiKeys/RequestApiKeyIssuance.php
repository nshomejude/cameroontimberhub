<?php

namespace App\Actions\ApiKeys;

use App\Models\ApiKeyIssuanceRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First step of the two-person API-key-issuance control (mirrors
 * App\Actions\Ai\RequestAiApiKeyChange exactly). An admin proposes a named
 * key with a set of Sanctum abilities for a company; this generates a
 * one-time invite token (returned in plaintext ONLY here, to be relayed by
 * the requester to a different admin out-of-band) and stores the token
 * pre-hashed. No Sanctum personal access token is created here — that only
 * happens once App\Actions\ApiKeys\ApproveApiKeyIssuance runs.
 */
class RequestApiKeyIssuance
{
    /** @param string[] $abilities
     *  @return array{request: ApiKeyIssuanceRequest, invite_token: string} */
    public function execute(Company $company, string $name, array $abilities, User $requestedBy): array
    {
        $inviteToken = Str::random(32);

        $request = ApiKeyIssuanceRequest::create([
            'company_id' => $company->getKey(),
            'requested_name' => $name,
            'requested_abilities' => $abilities,
            'invite_token_hash' => Hash::make($inviteToken),
            'expires_at' => now()->addHours(24),
            'status' => 'pending',
            'requested_by' => $requestedBy->getKey(),
        ]);

        return ['request' => $request, 'invite_token' => $inviteToken];
    }
}
