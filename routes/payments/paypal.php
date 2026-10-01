<?php

use App\Models\Payment;
use App\Services\Payments\PayPalGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * PayPal routes. Owned entirely by the PayPal integration — add the
 * webhook route here, not in routes/payments.php or any other
 * provider's file.
 */
// Return/cancel are public browser redirects from PayPal, so they only act
// on links we minted at checkout (temporary signed URLs). PayPal appends
// its own `token` / `PayerID` params, which are excluded from the signature.
Route::get('/payments/paypal/{payment}/return', function (Request $request, Payment $payment) {
    abort_unless($request->hasValidSignatureWhileIgnoring(['token', 'PayerID']), 403);

    return app(PayPalGateway::class)->handleReturn($request, $payment);
})->name('payments.paypal.return');

Route::get('/payments/paypal/{payment}/cancel', function (Request $request, Payment $payment) {
    abort_unless($request->hasValidSignatureWhileIgnoring(['token', 'PayerID']), 403);

    return app(PayPalGateway::class)->handleCancel($request, $payment);
})->name('payments.paypal.cancel');

Route::post('/payments/paypal/webhook', function (Request $request) {
    return app(PayPalGateway::class)->handleWebhook($request);
})->name('payments.paypal.webhook');
