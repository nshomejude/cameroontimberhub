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

// The phone-number form is posted by the signed-in buyer: only a member of
// the paying company may fire a Request-to-Pay push (404 for anyone else so
// payment ids are not enumerable), throttled against push-spam.
Route::middleware(['auth', 'throttle:6,1'])
    ->post('/payments/mtn-momo/{payment}/submit', function (Request $request, Payment $payment) {
        abort_unless(
            $request->user()->companies()->whereKey($payment->company_id)->exists(),
            404,
        );

        return app(MtnMomoGateway::class)->submit($request, $payment);
    })->name('payments.mtn-momo.submit');

Route::post('/payments/mtn-momo/webhook', function (Request $request) {
    return app(MtnMomoGateway::class)->handleWebhook($request);
})->name('payments.mtn-momo.webhook');
