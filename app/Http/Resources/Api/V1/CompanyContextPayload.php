<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * The company "context" blocks the mobile app needs to gate its UI the same
 * way the exporter panel does — shared by CompanyProfileResource
 * (`GET company`), UserResource::resolveCompany() (`/auth/me`, dashboard)
 * and `GET company/subscription`, so the three can never disagree.
 *
 *  - `organisation_type`: `{value, label}` of `Company::$type`, or null.
 *  - `plan`: the plan whose features apply RIGHT NOW
 *    ({@see Company::effectivePlan()} — the same resolution
 *    `Company::hasFeature()` gates on), with its raw `features` map
 *    (`leads_receive`, `max_gallery`, `verified_badge`, ...) and
 *    `expires_at` = the governing subscription's `ends_at` (null when no
 *    subscription row / open-ended).
 */
final class CompanyContextPayload
{
    /** @return array{value: string, label: string}|null */
    public static function organisationType(Company $company): ?array
    {
        $type = $company->type;

        return $type === null ? null : ['value' => $type->value, 'label' => $type->label()];
    }

    /** @return array<string, mixed>|null */
    public static function plan(Company $company): ?array
    {
        $plan = $company->effectivePlan();

        if ($plan === null) {
            return null;
        }

        $subscription = $company->currentSubscription;

        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'segment' => $plan->segment,
            'features' => (array) ($plan->features ?? []),
            'leads_receive' => (bool) $plan->feature('leads_receive', false),
            'max_gallery' => (int) $plan->feature('max_gallery', 3),
            'is_free' => $plan->isFree(),
            'expires_at' => $subscription?->entitled() && $subscription->plan_id === $plan->getKey()
                ? $subscription->ends_at?->toIso8601String()
                : null,
        ];
    }

    /**
     * `GET company/subscription` — the exporter SubscriptionStatus page's
     * data: the ACTIVE subscription (GetCompanySubscriptionQuery) and the plan
     * it shows (`$subscription->plan ?? $company->plan`), plus the effective
     * plan block above.
     *
     * @return array<string, mixed>
     */
    public static function subscription(Company $company, ?Subscription $subscription): array
    {
        /** @var Plan|null $shown */
        $shown = $subscription?->plan ?? $company->plan;

        return [
            'company_id' => $company->getKey(),
            'organisation_type' => self::organisationType($company),
            'plan' => $shown === null ? null : [
                'slug' => $shown->slug,
                'name' => $shown->name,
                'segment' => $shown->segment,
                'price_amount' => $shown->price_amount !== null ? (string) $shown->price_amount : null,
                'price_currency' => $shown->price_currency,
                'billing_period' => $shown->billing_period,
                'features' => (array) ($shown->features ?? []),
            ],
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->getKey(),
                'status' => $subscription->status?->value,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'renews_at' => $subscription->renews_at?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'on_trial' => $subscription->onTrial(),
            ],
            'effective_plan' => self::plan($company),
            'pricing_url' => route('pricing'),
        ];
    }
}
