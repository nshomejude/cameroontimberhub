<?php

use App\Models\Payment;
use App\Services\Payments\OrangeMoneyGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Orange Money routes. Owned entirely by the Orange Money integration —
 * add the webhook/callback route here, not in routes/payments.php or
 * any other provider's file.
 */

Route::get('/payments/orange-money/{payment}/return', function (Payment $payment) {
    return app(OrangeMoneyGateway::class)->returnPage($payment);
})->name('payments.orange-money.return');

Route::post('/payments/orange-money/notify', function (Request $request) {
    return app(OrangeMoneyGateway::class)->handleWebhook($request);
})->name('payments.orange-money.notify');
