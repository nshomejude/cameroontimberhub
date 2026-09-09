<?php

namespace App\Actions\Ai;

use App\Models\AiApiKeyChangeRequest;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Second step of the two-person API-key-change control (blueprint
 * §39/§88-89 pattern — mirrors App\Actions\Verification\ApproveVerificationRevocation
 * and App\Actions\Company\ApproveCompanySuspension). Requires ALL of:
 *  - the request is still pending and not expired
 *  - the approving user is not the same person who requested the change
 *  - the approver supplies the correct one-time invite token
 *  - the approver has a recent (within TwoFactorStepUp's window) confirmed TOTP code
 * Only then is the new key decrypted and written into ai_settings.
 */
class ApproveAiApiKeyChange
{
    public function __construct(private readonly TwoFactorStepUp $stepUp) {}

    public function execute(AiApiKeyChangeRequest $changeRequest, User $approvedBy, string $inviteToken, Request $httpRequest): void
    {
        if (! $changeRequest->isPending()) {
            throw ValidationException::withMessages(['status' => 'This API key change request has already been decided or has expired.']);
        }

        if ((int) $changeRequest->requested_by === (int) $approvedBy->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'The key change must be approved by a different administrator than the one who requested it.']);
        }

        if (! $changeRequest->inviteTokenMatches($inviteToken)) {
            throw ValidationException::withMessages(['invite_token' => 'That invite token is incorrect.']);
        }

        if (! $this->stepUp->isRecentlyVerified($httpRequest)) {
            throw ValidationException::withMessages(['two_factor' => 'Please re-confirm your two-factor code before approving an API key change.']);
        }

        $setting = AiSetting::forProvider($changeRequest->provider);
        $setting->update([
            'api_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($changeRequest->decryptedNewKey()),
            'updated_by' => $approvedBy->getKey(),
            'key_updated_at' => now(),
        ]);

        $changeRequest->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->getKey(),
            'decided_at' => now(),
        ]);
    }
}
