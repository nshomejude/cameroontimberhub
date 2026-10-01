<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a payment provider's processing-fee settings — **DB-first, then
 * config/env fallback** (same layering as GatewayCredentials) — and prices
 * a charge through the pure ProviderFeeCalculator.
 *
 * Sources, highest priority first:
 *  1. the provider's PaymentSetting row `fee_percent` / `fee_fixed` /
 *     `fee_bearer` (admin → Payment settings → "Edit fees"), each column
 *     overriding independently when non-null;
 *  2. config('payments.<provider>.fee_*') (env PAYPAL_FEE_PERCENT, …).
 *
 * The fixed fee is denominated in the provider block's `currency`. When a
 * charge is in a different currency (not reachable today — Plan::
 * checkoutProviders() routes USD plans to PayPal and XAF plans to mobile
 * money) the fixed part cannot be converted without an FX source, so only
 * the percentage is applied and a warning is logged.
 */
class ProviderFees
{
    /** @return array{percent: string, fixed: string, bearer: string, currency: string} */
    public static function for(PaymentProvider $provider): array
    {
        $config = (array) config('payments.'.$provider->value, []);

        $percent = (string) ($config['fee_percent'] ?? '0');
        $fixed = (string) ($config['fee_fixed'] ?? '0');
        $bearer = (string) ($config['fee_bearer'] ?? ProviderFeeCalculator::BEARER_PLATFORM);

        $setting = self::setting($provider);

        if ($setting !== null) {
            $percent = $setting->fee_percent !== null ? (string) $setting->fee_percent : $percent;
            $fixed = $setting->fee_fixed !== null ? (string) $setting->fee_fixed : $fixed;
            $bearer = filled($setting->fee_bearer) ? (string) $setting->fee_bearer : $bearer;
        }

        if (! in_array($bearer, [ProviderFeeCalculator::BEARER_BUYER, ProviderFeeCalculator::BEARER_PLATFORM], true)) {
            $bearer = ProviderFeeCalculator::BEARER_PLATFORM;
        }

        return [
            'percent' => is_numeric($percent) ? $percent : '0',
            'fixed' => is_numeric($fixed) ? $fixed : '0',
            'bearer' => $bearer,
            'currency' => strtoupper((string) ($config['currency'] ?? '')),
        ];
    }

    /**
     * Price a charge of $price (what the platform must net — plan price incl.
     * any tax) through $provider.
     *
     * @return array{base: string, fee: string, total: string, bearer: string, passed_through: bool, percent: string, fixed: string, currency: string, provider: string}
     */
    public static function breakdown(PaymentProvider $provider, float|string $price, string $currency): array
    {
        $fees = self::for($provider);
        $fixed = $fees['fixed'];

        if ($fees['currency'] !== '' && $fees['currency'] !== strtoupper($currency) && (float) $fixed > 0) {
            Log::warning('Provider fixed fee is in a different currency than the charge — applying the percentage only.', [
                'provider' => $provider->value,
                'fee_currency' => $fees['currency'],
                'charge_currency' => strtoupper($currency),
            ]);
            $fixed = '0';
        }

        $result = app(ProviderFeeCalculator::class)->calculate(
            bcadd(is_float($price) ? number_format($price, 2, '.', '') : $price, '0', 2),
            $currency,
            $fees['percent'],
            $fixed,
            $fees['bearer'],
        );

        return $result + ['provider' => $provider->value];
    }

    private static function setting(PaymentProvider $provider): ?PaymentSetting
    {
        // Guard for early boot / migrate:fresh where the table may not exist.
        if (! Schema::hasTable('payment_settings')) {
            return null;
        }

        return PaymentSetting::resolvedFor($provider);
    }
}
