<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Referrals\ReferralService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One user referred by the viewer. Name is masked ("Jean D.") — the referrer
 * never sees a referral's full name or email. `referral_status` and
 * `referral_earned_label` are computed by ReferralController::index().
 *
 * @mixin \App\Models\User
 */
class ReferralResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = (string) ($this->referral_status ?? 'signed_up');

        return [
            'id' => $this->id,
            'name_masked' => ReferralService::maskName($this->name),
            'joined_at' => ($this->referred_at ?? $this->created_at)?->toIso8601String(),
            'status' => $status,
            'status_label' => ReferralService::statusLabel($status),
            'earned_label' => (string) ($this->referral_earned_label ?? 'XAF 0'),
        ];
    }
}
