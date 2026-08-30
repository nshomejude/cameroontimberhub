<?php

use App\Contracts\PaymentGatewayContract;
use App\Enums\PaymentProvider;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Stripe routes. Owned entirely by the Stripe integration — add the
 * webhook route here, not in routes/payments.php or any other
 * provider's file.
 */
Route::get('/payments/stripe/success/{payment}', function (Payment $payment) {
    return redirect('/')
        ->with('status', 'Payment session complete — we will confirm shortly.');
})->name('payments.stripe.success');

Route::get('/payments/stripe/cancel/{payment}', function (Payment $payment) {
    $payment->markFailed();

    return redirect('/')
        ->with('status', 'Payment was cancelled.');
})->name('payments.stripe.cancel');

Route::withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->post('/payments/stripe/webhook', function (Request $request) {
        /** @var PaymentGatewayContract $gateway */
        $gateway = app(config('payments.providers.'.PaymentProvider::Stripe->value));

        return $gateway->handleWebhook($request);
    })
    ->name('payments.stripe.webhook');
