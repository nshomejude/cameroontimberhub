<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReferralEarningStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReferralEarningResource;
use App\Http\Resources\Api\V1\ReferralResource;
use App\Models\ReferralEarning;
use App\Models\ReferralPayoutProfile;
use App\Models\User;
use App\Services\Referrals\ReferralPayoutService;
use App\Services\Referrals\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Referral programme for the signed-in user (mobile "Refer & earn" screen).
 * Shapes are fixed by the app — keep field names stable.
 */
class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly ReferralPayoutService $payouts,
    ) {}

    /** GET /api/v1/referrals/me */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $code = $this->referrals->codeFor($user);
        $settings = $this->referrals->settings();

        $referredCount = User::where('referred_by_user_id', $user->id)->count();
        $earnings = ReferralEarning::where('referrer_user_id', $user->id)
            ->where('status', '!=', ReferralEarningStatus::Cancelled->value)
            ->get(['referred_company_id', 'amount', 'currency', 'status']);

        $percent = (float) $settings->rate_percent;
        $percentText = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');

        return response()->json([
            'data' => [
                'code' => $code,
                'share_url' => route('register', ['ref' => $code]),
                'stats' => [
                    'invited' => $referredCount,
                    'signed_up' => $referredCount,
                    'qualified' => $earnings->pluck('referred_company_id')->unique()->count(),
                    'earned_label' => self::sumLabel($earnings),
                    'pending_label' => self::sumLabel($earnings->filter(fn ($e) => in_array($e->status, [ReferralEarningStatus::Pending, ReferralEarningStatus::Approved], true))),
                    'paid_label' => self::sumLabel($earnings->where('status', ReferralEarningStatus::Paid)),
                ],
                'payout' => $this->payoutBlock($user),
                'terms' => [
                    'summary' => $settings->enabled
                        ? "Earn {$percentText}% of a referred company's first subscription payment. Paid once per company; renewals are not included."
                        : 'The referral programme is currently paused. Referrals made now are still recorded.',
                    'commission_percent' => $percent,
                    'basis' => $settings->basis,
                    'one_time' => (bool) $settings->one_time,
                ],
            ],
        ]);
    }

    /** GET /api/v1/referrals */
    public function index(Request $request): AnonymousResourceCollection
    {
        $referred = $this->referrals->referredUsers($request->user())
            ->each(fn (User $u) => $u->setAttribute('referral_earned_label', self::sumLabel($u->getAttribute('referral_earnings'))));

        return ReferralResource::collection($referred);
    }

    /** GET /api/v1/referrals/earnings */
    public function earnings(Request $request): AnonymousResourceCollection
    {
        return ReferralEarningResource::collection(
            ReferralEarning::where('referrer_user_id', $request->user()->id)
                ->with('latestPayout')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(200)
                ->get()
        );
    }

    /**
     * PATCH /api/v1/referrals/payout-settings — where commissions are paid.
     * Send `paypal_payout_email` and/or `manual_payout_details` (MoMo number
     * / bank details for commissions paid by hand, e.g. XAF); a key that is
     * left out is unchanged, `null` (or "") removes it. At least one key is
     * required. Both are only ever returned masked.
     */
    public function updatePayoutSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Pre-existing clients always send the email; it is optional only
            // when the request updates the manual details instead.
            'paypal_payout_email' => [$request->exists('manual_payout_details') ? 'sometimes' : 'present', ...ReferralPayoutService::PAYPAL_EMAIL_RULES],
            'manual_payout_details' => ReferralPayoutService::MANUAL_DETAILS_RULES,
        ]);

        if ($request->exists('paypal_payout_email')) {
            $this->payouts->setPaypalEmail($request->user(), $data['paypal_payout_email'] ?? null);
        }

        if ($request->exists('manual_payout_details')) {
            $this->payouts->setManualPayoutDetails($request->user(), $data['manual_payout_details'] ?? null);
        }

        return response()->json(['data' => $this->payoutBlock($request->user())]);
    }

    /** @return array{paypal_email_masked: ?string, has_paypal_email: bool, paypal_available: bool, paypal_currencies: list<string>, manual_payout_details_masked: ?string, has_manual_payout_details: bool} */
    private function payoutBlock(User $user): array
    {
        $profile = ReferralPayoutProfile::forUser($user);
        $masked = $profile?->maskedPaypalEmail();
        $manual = $profile?->maskedManualPayoutDetails();

        return [
            'paypal_email_masked' => $masked,
            'has_paypal_email' => $masked !== null,
            'paypal_available' => $this->payouts->paypalConfigured(),
            'paypal_currencies' => ReferralPayoutService::paypalCurrencies(),
            'manual_payout_details_masked' => $manual,
            'has_manual_payout_details' => $manual !== null,
        ];
    }

    /** @param Collection<int, ReferralEarning> $earnings */
    private static function sumLabel(Collection $earnings): string
    {
        if ($earnings->isEmpty()) {
            return ReferralEarning::money(0, 'XAF');
        }

        return $earnings->groupBy('currency')
            ->map(fn (Collection $group, string $currency) => ReferralEarning::money((float) $group->sum(fn ($e) => (float) $e->amount), $currency))
            ->values()
            ->implode(' + ');
    }
}
