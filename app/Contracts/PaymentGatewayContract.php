<?php

namespace App\Contracts;

use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One implementation per gateway (MTN Mobile Money, Orange Money, Stripe,
 * PayPal — App\Enums\PaymentProvider). Each implementation lives under
 * app/Services/Payments/{Provider}Gateway.php and is registered in
 * config/payments.php's `providers` map, resolved by PaymentCheckoutController
 * via `app(config('payments.providers.'.$provider->value))`.
 *
 * No gateway implementation may execute a real charge until real API
 * credentials are present in config (env-backed) — every provider's config
 * block ships with null placeholders. An implementation whose required
 * config keys are missing MUST refuse to initiate a payment (throw, or
 * return a response explaining the gateway isn't configured yet) rather
 * than silently proceeding or faking success.
 */
interface PaymentGatewayContract
{
    /**
     * Start a payment for this already-created, still-pending Payment row.
     * Returns whatever response the checkout flow needs to hand back to the
     * browser: a redirect to a hosted checkout page (Stripe/PayPal), or a
     * response confirming a push-payment prompt was sent to the payer's
     * phone (MTN/Orange Mobile Money) with instructions to check their
     * phone and that the platform will confirm via webhook.
     */
    public function initiate(Payment $payment): RedirectResponse|Response;

    /**
     * Handle this gateway's incoming webhook/callback request. Must verify
     * the request's authenticity (signature/secret — never trust an
     * unverified webhook to mark a Payment completed), then update the
     * matching Payment row's status via markCompleted()/markFailed().
     */
    public function handleWebhook(Request $request): Response;

    /** True only when this gateway's required config (API keys, etc.) is actually present — not a fake/placeholder check. */
    public function isConfigured(): bool;
}
