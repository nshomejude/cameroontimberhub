<?php

namespace App\Actions\Ai;

use App\Enums\AiProvider;
use App\Models\AiApiKeyChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First step of the two-person API-key-change control. An admin proposes a
 * new key for a provider; this generates a one-time invite token (returned
 * in plaintext ONLY here, to be relayed by the requester to a different
 * admin out-of-band — e.g. verbally, or via a separate secure channel) and
 * stores the token pre-hashed. The new key itself is encrypted at rest and
 * never applied until App\Actions\Ai\ApproveAiApiKeyChange runs.
 */
class RequestAiApiKeyChange
{
    /** @return array{request: AiApiKeyChangeRequest, invite_token: string} */
    public function execute(AiProvider $provider, string $newApiKey, User $requestedBy): array
    {
        $inviteToken = Str::random(32);

        $request = AiApiKeyChangeRequest::create([
            'provider' => $provider->value,
            'new_api_key_encrypted' => Crypt::encryptString($newApiKey),
            'invite_token_hash' => Hash::make($inviteToken),
            'expires_at' => now()->addHours(24),
            'status' => 'pending',
            'requested_by' => $requestedBy->getKey(),
        ]);

        return ['request' => $request, 'invite_token' => $inviteToken];
    }
}
