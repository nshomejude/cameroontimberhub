<?php

namespace App\Actions\Payments;

use App\Enums\PaymentProvider;
use App\Models\PaymentCredentialChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First step of the two-person payment-credential-change control (copy of
 * App\Actions\Ai\RequestAiApiKeyChange). An admin proposes new credentials
 * for a provider; this generates a one-time invite token (returned in
 * plaintext ONLY here, to be relayed to a different admin out-of-band) and
 * stores the token pre-hashed. The credentials themselves are encrypted at
 * rest and never applied until ApprovePaymentCredentialChange runs.
 */
class RequestPaymentCredentialChange
{
    /**
     * @param  array<string, string|null>  $proposedCredentials
     * @return array{request: PaymentCredentialChangeRequest, invite_token: string}
     */
    public function execute(PaymentProvider $provider, array $proposedCredentials, string $environment, User $requestedBy): array
    {
        $inviteToken = Str::random(32);

        $request = PaymentCredentialChangeRequest::create([
            'provider' => $provider->value,
            'environment' => $environment,
            'proposed_credentials' => array_filter(
                $proposedCredentials,
                static fn ($value) => filled($value),
            ),
            'invite_token_hash' => Hash::make($inviteToken),
            'expires_at' => now()->addHours(24),
            'status' => 'pending',
            'requested_by' => $requestedBy->getKey(),
        ]);

        return ['request' => $request, 'invite_token' => $inviteToken];
    }
}
