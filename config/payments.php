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
    ],

    'orange_money' => [
        'environment' => env('ORANGE_MONEY_ENVIRONMENT', 'sandbox'),
        'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
        'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
        'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'),
        'currency' => env('ORANGE_MONEY_CURRENCY', 'XAF'),
    ],

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
    ],

    'paypal' => [
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'), // 'sandbox' | 'live'
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'currency' => env('PAYPAL_CURRENCY', 'USD'),
    ],

];
