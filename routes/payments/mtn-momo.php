<?php

use App\Models\Payment;
use App\Services\Payments\MtnMomoGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * MTN Mobile Money routes. Owned entirely by the MTN MoMo integration —
 * add the webhook/callback route here, not in routes/payments.php or
 * any other provider's file.
 */

Route::post('/payments/mtn-momo/{payment}/submit', function (Request $request, Payment $payment) {
    return app(MtnMomoGateway::class)->submit($request, $payment);
})->name('payments.mtn-momo.submit');

Route::post('/payments/mtn-momo/webhook', function (Request $request) {
    return app(MtnMomoGateway::class)->handleWebhook($request);
})->name('payments.mtn-momo.webhook');
