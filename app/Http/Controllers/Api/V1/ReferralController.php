<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReferralEarningStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReferralEarningResource;
use App\Http\Resources\Api\V1\ReferralResource;
use App\Models\ReferralEarning;
use App\Models\User;
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
    public function __construct(private readonly ReferralService $referrals) {}

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
        $referred = User::where('referred_by_user_id', $request->user()->id)
            ->with('companies')
            ->orderByDesc('referred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $earnings = ReferralEarning::where('referrer_user_id', $request->user()->id)
            ->where('status', '!=', ReferralEarningStatus::Cancelled->value)
            ->get();

        $referred->each(function (User $u) use ($earnings): void {
            $company = $u->companies->first();
            $mine = $earnings->filter(fn (ReferralEarning $e) => $e->referred_user_id === $u->id
                || ($company && $e->referred_company_id === $company->id));

            $status = $this->referrals->statusFor($u, $company, $mine->isNotEmpty());
            $u->setAttribute('referral_status', $status);
            $u->setAttribute('referral_earned_label', self::sumLabel($mine));
        });

        return ReferralResource::collection($referred);
    }

    /** GET /api/v1/referrals/earnings */
    public function earnings(Request $request): AnonymousResourceCollection
    {
        return ReferralEarningResource::collection(
            ReferralEarning::where('referrer_user_id', $request->user()->id)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(200)
                ->get()
        );
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
