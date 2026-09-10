<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a payment gateway's effective credentials as **DB-first, then
 * config/env fallback**.
 *
 * Why this lives in a service and NOT in config/payments.php: `config/*` is
 * compiled by `php artisan config:cache` at deploy time, and the cache
 * builder must never touch the database (no connection is guaranteed, and
 * the encryption key rotation story gets ugly). So config/payments.php stays
 * pure env(), and this service layers the encrypted PaymentSetting row on
 * top at request time — the merge happens per-call, never at cache-build.
 *
 * The gateways (MtnMomoGateway, OrangeMoneyGateway, StripeGateway,
 * PayPalGateway) call GatewayCredentials::for($provider) instead of
 * config('payments.<provider>') directly.
 */
class GatewayCredentials
{
    /** @return array<string, mixed> config block with any live PaymentSetting values merged over the top */
    public static function for(PaymentProvider $provider): array
    {
        $config = (array) config('payments.'.$provider->value, []);

        $setting = self::setting($provider);

        if (! $setting || ! $setting->is_live) {
            return $config;
        }

        $config['environment'] = $setting->environment ?: ($config['environment'] ?? 'sandbox');

        foreach ((array) $setting->credentials as $key => $value) {
            if (filled($value)) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * True when this provider has a usable credential set — a live
     * PaymentSetting row with every required key, OR the env fallback fully
     * populated.
     */
    public static function isConfigured(PaymentProvider $provider): bool
    {
        $setting = self::setting($provider);

        if ($setting && $setting->isConfigured()) {
            return true;
        }

        $merged = self::for($provider);

        foreach (PaymentSetting::REQUIRED_KEYS[$provider->value] ?? [] as $key) {
            if (blank($merged[$key] ?? null)) {
                return false;
            }
        }

        return (PaymentSetting::REQUIRED_KEYS[$provider->value] ?? []) !== [];
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
