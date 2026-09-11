<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyDocumentController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderDocumentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\RfqController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SpeciesController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TradeAssuranceController;
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
| v1 is browse + RFQ + quotes + orders + Trade Assurance view/confirm.
| Messaging, documents, receipts and reorder stay on the web for now.
|
*/

// Per-API-key rate limiting (API-First plan Task 0.4), additive on top of
// the existing per-endpoint `throttle:api-*` limiters above/below — this
// one is keyed by the Sanctum token id (or IP when unauthenticated), not
// by endpoint, so it bounds a single key's total v1 traffic. See
// App\Providers\AppServiceProvider::registerRateLimiters().
//
// RecordApiKeyUsage (API-as-a-product usage metering) rides alongside it,
// additively — it never blocks or fails the request (see its own
// doc block), it just increments today's usage row for the token.
// --------------------------------------------------------------------------
// Deprecation signalling (GAPS.md gap 5 — mechanism now available)
// --------------------------------------------------------------------------
// `/api/v1` is additive-only; NOTHING is deprecated today. When the first v1
// endpoint (or all of v1) must be sunset, declare the `deprecated` middleware
// on that route/group. It emits RFC 8594 headers: `Deprecation: <HTTP-date>`,
// `Sunset: <HTTP-date>`, `Link: <successor>; rel="successor-version"` and an
// optional `Warning: 299 - "..."`. Params:
//   deprecated:<deprecation-date>,<sunset-date>,<successor-url>,<note>
// all optional, parsed defensively. See docs/api/CONVENTIONS.md.
//
// EXAMPLE — a single endpoint superseded by a v2 equivalent (commented out):
//
//   Route::get('products/{slug}', [ProductController::class, 'show'])
//       ->middleware('deprecated:2027-01-01,2027-07-01,https://www.cameroontimberhub.com/api/v2/products/{slug},Use /api/v2/products/{slug}')
//       ->name('products.show');
//
// EXAMPLE — the whole v1 group after v2 ships (commented out):
//
//   Route::prefix('v1')->name('api.v1.')
//       ->middleware(['throttle:api-key', \App\Http\Middleware\RecordApiKeyUsage::class,
//           'deprecated:2027-01-01,2027-07-01,https://www.cameroontimberhub.com/api/v2'])
//       ->group(function (): void { /* ... */ });

// AssignRequestId (GAPS.md §6) is prepended so the correlation id is set
// before throttle:api-key runs and is available to the standardized error
// envelope for every v1 response (including a 429 from the limiter below).
// It is also on the `web` group (see bootstrap/app.php) for admin-action
// correlation.
Route::prefix('v1')->name('api.v1.')->middleware([\App\Http\Middleware\AssignRequestId::class, 'throttle:api-key', \App\Http\Middleware\RecordApiKeyUsage::class])->group(function (): void {

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

    /* --------------------------------------------- any authenticated user */

    Route::middleware(['auth:sanctum'])->group(function (): void {
        // Home-screen feed, open to all three populations (RBAC foundation
        // — buyer/supplier/staff). DashboardController resolves the caller's
        // role the same way UserResource::resolveRole() does and switches
        // shape: a buyer gets the full BuyerDashboard-backed payload; a
        // supplier or staff member gets an honest empty-but-valid payload
        // (200, not 403) until their own dashboards are built — see the
        // controller's docblock.
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Plain buyer<->supplier messaging (read/post-a-plain-message + inbox +
        // mark-read only — see ConversationController's docblock for what is
        // deliberately NOT exposed here yet). Open to ANY authenticated
        // participant, not just buyers: Conversation::scopeForParticipant()
        // resolves membership from the participant row itself (a supplier's
        // company membership counts, minted lazily on first access via
        // MessagingService::participantFor()), so a role gate here would be
        // wrong, not just incomplete — a conversation has a buyer side and a
        // supplier side, and both must be able to hold up their end from the
        // app. Authorization is still enforced per-conversation (404, not
        // 403, for a non-participant's id) via MessagingService::find().
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');

        Route::get('conversations/{id}', [ConversationController::class, 'show'])->name('conversations.show');

        Route::get('conversations/{id}/messages', [ConversationController::class, 'messages'])->name('conversations.messages');

        // Same write-throttle budget as the other decision endpoints (quotes
        // accept/decline, dispute reply) — see the buyer group below for the
        // shared `api-decision` limiter.
        Route::post('conversations/{id}/messages', [ConversationController::class, 'postMessage'])
            ->middleware('throttle:api-decision')->name('conversations.messages.store');

        Route::post('conversations/{id}/read', [ConversationController::class, 'markRead'])->name('conversations.read');
    });

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

        // Orders + Trade Assurance (API-First plan Phase 1 §4): parity with
        // /account/orders and /account/orders/{order}/trade-assurance. Both
        // read through the same domain code the web uses (ListBuyerOrdersQuery
        // via QueryBus, TradeAssuranceMilestone::confirmByBuyer()) — no second
        // write/read path.
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{reference}', [OrderController::class, 'show'])->name('orders.show');

        // Shipment/checkpoint tracking (Logistics follow-up to Phase 1 §4):
        // GetOrderShipmentTrackingQuery via QueryBus, scoped through the same
        // BuyerApiScope::order() boundary as show()/trade-assurance above.
        Route::get('orders/{reference}/shipments', [OrderController::class, 'shipmentTracking'])
            ->name('orders.shipments');

        Route::get('orders/{orderReference}/trade-assurance', [TradeAssuranceController::class, 'show'])
            ->name('orders.trade-assurance.show');

        Route::post('orders/{orderReference}/trade-assurance/milestones/{milestoneId}/confirm', [TradeAssuranceController::class, 'confirmMilestone'])
            ->middleware('throttle:api-decision')->name('orders.trade-assurance.confirm');

        // Dispute Resolution (blueprint §64): API counterpart of the web
        // `orders/{order}/disputes` group. See Api\V1\DisputeController's
        // docblock for what is/isn't covered in this first pass (evidence
        // file upload and appeal are deferred).
        Route::get('orders/{orderReference}/disputes', [DisputeController::class, 'index'])
            ->name('orders.disputes.index');

        Route::get('orders/{orderReference}/disputes/{dispute}', [DisputeController::class, 'show'])
            ->name('orders.disputes.show');

        Route::post('orders/{orderReference}/disputes', [DisputeController::class, 'store'])
            ->middleware('throttle:api-decision')->name('orders.disputes.store');

        Route::post('orders/{orderReference}/disputes/{dispute}/reply', [DisputeController::class, 'reply'])
            ->middleware('throttle:api-decision')->name('orders.disputes.reply');

        // Order documents (proof of delivery, invoice, packing list, ...):
        // stays inside this same buyer-only `orders/{orderReference}/...`
        // family, scoped through the exact BuyerApiScope::order() boundary
        // every other route in this group uses. See
        // OrderDocumentController's docblock for why this deliberately does
        // NOT widen order access to suppliers in this task.
        Route::get('orders/{orderReference}/documents', [OrderDocumentController::class, 'index'])
            ->name('orders.documents.index');

        Route::get('orders/{orderReference}/documents/{document}/download', [OrderDocumentController::class, 'download'])
            ->name('orders.documents.download');
    });

    /* ----------------------------------------------- supplier (authenticated) */

    Route::middleware(['auth:sanctum', 'api.supplier'])->group(function (): void {
        // A supplier's own company's compliance documents (blueprint's
        // compliance-document flow, API-exposed for the mobile app). See
        // CompanyDocumentController's docblock.
        Route::get('company/documents', [CompanyDocumentController::class, 'index'])
            ->name('company.documents.index');

        Route::post('company/documents', [CompanyDocumentController::class, 'store'])
            ->middleware('throttle:api-rfq')->name('company.documents.store');

        Route::get('company/documents/{document}/download', [CompanyDocumentController::class, 'download'])
            ->name('company.documents.download');
    });
});
