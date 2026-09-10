<?php

namespace App\Actions\Payments;

use App\Models\PaymentCredentialChangeRequest;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Second step of the two-person payment-credential-change control (copy of
 * App\Actions\Ai\ApproveAiApiKeyChange). Requires ALL of:
 *  - the request is still pending and not expired
 *  - the approving user is not the same person who requested the change
 *  - the approver supplies the correct one-time invite token
 *  - the approver has a recent (within TwoFactorStepUp's window) confirmed TOTP code
 * Only then are the new credentials decrypted and written (encrypted) into
 * the payment_settings row for that provider, flipping is_live on.
 */
class ApprovePaymentCredentialChange
{
    public function __construct(private readonly TwoFactorStepUp $stepUp) {}

    public function execute(PaymentCredentialChangeRequest $changeRequest, User $approvedBy, string $inviteToken, Request $httpRequest): void
    {
        if (! $changeRequest->isPending()) {
            throw ValidationException::withMessages(['status' => 'This credential change request has already been decided or has expired.']);
        }

        if ((int) $changeRequest->requested_by === (int) $approvedBy->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'The credential change must be approved by a different administrator than the one who requested it.']);
        }

        if (! $changeRequest->inviteTokenMatches($inviteToken)) {
            throw ValidationException::withMessages(['invite_token' => 'That invite token is incorrect.']);
        }

        if (! $this->stepUp->isRecentlyVerified($httpRequest)) {
            throw ValidationException::withMessages(['two_factor' => 'Please re-confirm your two-factor code before approving a payment credential change.']);
        }

        $setting = PaymentSetting::forProvider($changeRequest->provider);
        $setting->update([
            'credentials' => $changeRequest->proposed_credentials,
            'environment' => $changeRequest->environment,
            'is_live' => true,
            'updated_by' => $approvedBy->getKey(),
        ]);

        $changeRequest->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->getKey(),
            'decided_at' => now(),
        ]);
    }
}
