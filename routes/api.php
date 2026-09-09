<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RfqController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SpeciesController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Buyer JSON API — v1
|--------------------------------------------------------------------------
|
| The transport layer for the React Native buyer app. Every write goes
| through the same domain service the web uses (IntakeService for RFQs,
| QuoteService for quote decisions), so there is no second write path that
| could bypass anti-spam, the verification gate, the state machines or the
| authorisation rules.
|
| v1 is browse + RFQ + quotes. Orders beyond the award receipt, messaging,
| documents and reorder stay on the web for now.
|
*/

// Per-API-key rate limiting (API-First plan Task 0.4), additive on top of
// the existing per-endpoint `throttle:api-*` limiters above/below — this
// one is keyed by the Sanctum token id (or IP when unauthenticated), not
// by endpoint, so it bounds a single key's total v1 traffic. See
// App\Providers\AppServiceProvider::registerRateLimiters().
Route::prefix('v1')->name('api.v1.')->middleware('throttle:api-key')->group(function (): void {

    /* ------------------------------------------------------------- auth */

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:api-register')->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:api-login')->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
        });
    });

    /* -------------------------------------------------- catalogue (public) */

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');

    Route::get('species', [SpeciesController::class, 'index'])->name('species.index');
    Route::get('species/{slug}', [SpeciesController::class, 'show'])->name('species.show');

    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/{slug}', [SupplierController::class, 'show'])->name('suppliers.show');

    Route::get('search', SearchController::class)->name('search');

    /* ------------------------------------------------- buyer (authenticated) */

    Route::middleware(['auth:sanctum', 'api.buyer'])->group(function (): void {
        Route::get('rfqs', [RfqController::class, 'index'])->name('rfqs.index');

        Route::post('rfqs', [RfqController::class, 'store'])
            ->middleware('throttle:api-rfq')->name('rfqs.store');

        Route::get('rfqs/{reference}', [RfqController::class, 'show'])->name('rfqs.show');

        // The buyer's only self-service lever on an RFQ stuck behind the email
        // verification gate. Rate-limited on its own budget: it costs an
        // outbound mail, and a resend loop must not be able to use someone's
        // inbox as a hose.
        Route::post('rfqs/{reference}/resend-verification', [RfqController::class, 'resendVerification'])
            ->middleware('throttle:api-rfq-verify')->name('rfqs.resend-verification');

        Route::get('rfqs/{reference}/quotes', [RfqController::class, 'quotes'])->name('rfqs.quotes');

        Route::get('quotes/{reference}', [QuoteController::class, 'show'])->name('quotes.show');

        Route::post('quotes/{reference}/accept', [QuoteController::class, 'accept'])
            ->middleware('throttle:api-decision')->name('quotes.accept');

        Route::post('quotes/{reference}/decline', [QuoteController::class, 'decline'])
            ->middleware('throttle:api-decision')->name('quotes.decline');
    });
});
