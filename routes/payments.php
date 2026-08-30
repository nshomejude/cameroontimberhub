<?php

use App\Http\Controllers\Public\PaymentCheckoutController;
use Illuminate\Support\Facades\Route;

/**
 * Shared checkout entry point (auth required — a company must be signed
 * in to buy a plan) plus one route file per gateway for its own
 * provider-specific routes (typically just a webhook/callback endpoint,
 * which must stay outside auth middleware since the gateway calls it, not
 * a logged-in browser). Each provider file is owned by that provider's
 * integration — see routes/payments/{provider}.php.
 */
Route::middleware('auth')
    ->post('/payments/checkout/{plan}', [PaymentCheckoutController::class, 'start'])
    ->name('payments.checkout');

require __DIR__.'/payments/mtn-momo.php';
require __DIR__.'/payments/orange-money.php';
require __DIR__.'/payments/stripe.php';
require __DIR__.'/payments/paypal.php';
