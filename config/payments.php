<?php

/**
 * Payment gateway configuration. Every credential below is env-backed and
 * ships as null/placeholder — no real API keys exist in this codebase.
 * Each provider's PaymentGatewayContract implementation must check
 * isConfigured() and refuse to process a real payment until its
 * environment variables are actually populated with live credentials.
 *
 * See app/Contracts/PaymentGatewayContract.php and
 * app/Services/Payments/{Provider}Gateway.php for the implementations,
 * and app/Http/Controllers/Public/PaymentCheckoutController.php for the
 * shared checkout entry point that resolves a provider from this map.
 */

use App\Enums\PaymentProvider;

return [

    /*
    | Provider processing fees (billing: PayPal commission structure, part A)
    |
    | Each provider block below carries:
    |   fee_percent  — the provider's percentage fee as a PERCENT (4.4 = 4.4%)
    |   fee_fixed    — the provider's fixed per-transaction fee, expressed in
    |                  the provider block's `currency` (PayPal 0.30 USD)
    |   fee_bearer   — 'buyer'    → passed through: the checkout total is
    |                               grossed up so the platform nets the list
    |                               price, and the fee is disclosed as its
    |                               own line BEFORE the payer authorises
    |                               (docs/PRICING_SPEC.md §19/§20)
    |                  'platform' → absorbed: the payer is charged the list
    |                               price and the fee is recorded as a
    |                               platform cost (finance report only)
    |
    | A PaymentSetting row's fee_* columns (admin → Payment settings → "Edit
    | fees") override these env values at request time — see
    | App\Services\Payments\ProviderFees. The math itself is the pure
    | App\Services\Payments\ProviderFeeCalculator.
    */

    'providers' => [
        PaymentProvider::MtnMomo->value => \App\Services\Payments\MtnMomoGateway::class,
        PaymentProvider::OrangeMoney->value => \App\Services\Payments\OrangeMoneyGateway::class,
        PaymentProvider::Stripe->value => \App\Services\Payments\StripeGateway::class,
        PaymentProvider::PayPal->value => \App\Services\Payments\PayPalGateway::class,
    ],

    'mtn_momo' => [
        'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'), // 'sandbox' | 'production'
        'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
        'api_user' => env('MTN_MOMO_API_USER'),
        'api_key' => env('MTN_MOMO_API_KEY'),
        'target_environment' => env('MTN_MOMO_TARGET_ENVIRONMENT', 'mtncameroon'),
        'callback_host' => env('MTN_MOMO_CALLBACK_HOST'),
        'currency' => env('MTN_MOMO_CURRENCY', 'XAF'),
        'fee_percent' => (float) env('MTN_MOMO_FEE_PERCENT', 0),
        'fee_fixed' => (float) env('MTN_MOMO_FEE_FIXED', 0),
        'fee_bearer' => env('MTN_MOMO_FEE_BEARER', 'platform'), // 'buyer' | 'platform'
    ],

    'orange_money' => [
        'environment' => env('ORANGE_MONEY_ENVIRONMENT', 'sandbox'),
        'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
        'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
        'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'),
        'currency' => env('ORANGE_MONEY_CURRENCY', 'XAF'),
        'fee_percent' => (float) env('ORANGE_MONEY_FEE_PERCENT', 0),
        'fee_fixed' => (float) env('ORANGE_MONEY_FEE_FIXED', 0),
        'fee_bearer' => env('ORANGE_MONEY_FEE_BEARER', 'platform'), // 'buyer' | 'platform'
    ],

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
        'fee_percent' => (float) env('STRIPE_FEE_PERCENT', 3.4),
        'fee_fixed' => (float) env('STRIPE_FEE_FIXED', 0.30),
        'fee_bearer' => env('STRIPE_FEE_BEARER', 'platform'), // 'buyer' | 'platform'
    ],

    'paypal' => [
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'), // 'sandbox' | 'live'
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'currency' => env('PAYPAL_CURRENCY', 'USD'),
        'fee_percent' => (float) env('PAYPAL_FEE_PERCENT', 4.4),
        'fee_fixed' => (float) env('PAYPAL_FEE_FIXED', 0.30),
        'fee_bearer' => env('PAYPAL_FEE_BEARER', 'buyer'), // 'buyer' | 'platform'
    ],

    // Referral commission payouts via the PayPal Payouts API (same PayPal
    // credentials as above; Payouts must be enabled on the business account).
    // Only earnings in these currencies can be sent through PayPal — PayPal
    // does not support XAF, so XAF commissions are paid manually (MoMo/bank).
    'paypal_payouts' => [
        'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYPAL_PAYOUT_CURRENCIES', 'USD,EUR,GBP'))))),
    ],

];
