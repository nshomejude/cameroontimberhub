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
Route::get('/payments/paypal/{payment}/return', function (Request $request, Payment $payment) {
    return app(PayPalGateway::class)->handleReturn($request, $payment);
})->name('payments.paypal.return');

Route::get('/payments/paypal/{payment}/cancel', function (Request $request, Payment $payment) {
    return app(PayPalGateway::class)->handleCancel($request, $payment);
})->name('payments.paypal.cancel');

Route::post('/payments/paypal/webhook', function (Request $request) {
    return app(PayPalGateway::class)->handleWebhook($request);
})->name('payments.paypal.webhook');
