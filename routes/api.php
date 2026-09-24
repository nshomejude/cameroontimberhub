<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyDocumentController;
use App\Http\Controllers\Api\V1\CompanyOnboardingController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\CompanyVerificationController;
use App\Http\Controllers\Api\V1\FleetDriverController;
use App\Http\Controllers\Api\V1\FleetVehicleController;
use App\Http\Controllers\Api\V1\ChatCommerceController;
use App\Http\Controllers\Api\V1\ChatOrderController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DemoLoginController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderDocumentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\QuoteController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\CompanyReviewController;
use App\Http\Controllers\Api\V1\ReorderController;
use App\Http\Controllers\Api\V1\RfqController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SpeciesController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierOrderController;
use App\Http\Controllers\Api\V1\SupplierProductController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\SupplierProductImageController;
use App\Http\Controllers\Api\V1\SupplierQuoteController;
use App\Http\Controllers\Api\V1\SupplierRfqController;
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
| v1 is browse + RFQ + quotes + orders + Trade Assurance view/confirm +
| documents + receipts. Reorder stays on the web for now.
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

        // One-click demo logins (mobile mirror of the web `/demo-login`
        // flow) — see DemoLoginController's docblock. Public on purpose
        // (that's the point), gated by the same DemoLoginsEnabled Pennant
        // flag and a dedicated throttle, same as the web route.
        Route::get('demo-personas', [DemoLoginController::class, 'personas'])
            ->name('demo-personas');

        Route::post('demo-login/{persona}', [DemoLoginController::class, 'login'])
            ->middleware('throttle:demo-login')->name('demo-login');

        // Blueprint §39 mobile 2FA: second half of a login AuthController
        // paused for a confirmed 2FA account. Public (no bearer token yet —
        // that's the point), but rate-limited hard against brute force.
        Route::post('two-factor/challenge', [TwoFactorController::class, 'challenge'])
            ->middleware('throttle:api-2fa-challenge')->name('two-factor.challenge');

        // Forgot/reset password — JSON counterpart of the web session+redirect
        // flow (PasswordResetLinkController / NewPasswordController), public
        // by necessity (the caller has no token yet). `api-forgot-password`
        // mirrors `api-register`'s per-IP shape so this can't become an
        // email-bombing vector.
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:api-forgot-password')->name('forgot-password');

        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:api-forgot-password')->name('reset-password');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::patch('me', [AuthController::class, 'updateMe'])->name('me.update');
            Route::post('password', [AuthController::class, 'updatePassword'])->name('password.update');

            // Self-service 2FA management, mobile counterpart of the web
            // Auth\TwoFactorController (see that class's docblock).
            Route::get('two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
            Route::post('two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
            Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
            Route::post('two-factor/disable', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
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

    // Mobile home-screen feed (Announcements). Public, no auth, always
    // `{"data": [...]}` — see AnnouncementController.
    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');

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

        /*
         * ---------------------------------------------- in-thread commerce
         *
         * The mobile-app counterpart of the web `chat.*` route group
         * (routes/web.php, "In-thread commerce"). Same any-participant
         * conversation group as above — the buyer/supplier split is a
         * property of the thread, decided entirely by ChatCommerceService /
         * OrderLifecycleService / ReorderService, never by middleware here.
         * Every write reuses the SAME throttle bucket the web route for the
         * same action uses.
         */
        Route::post('conversations/{id}/rfq', [ChatCommerceController::class, 'storeRfq'])
            ->middleware('throttle:chat-rfq')->name('conversations.rfq');

        Route::post('conversations/{id}/quotes/{quote}/accept', [ChatCommerceController::class, 'acceptQuote'])
            ->middleware('throttle:chat-decision')->name('conversations.quotes.accept');
        Route::post('conversations/{id}/quotes/{quote}/decline', [ChatCommerceController::class, 'declineQuote'])
            ->middleware('throttle:chat-decision')->name('conversations.quotes.decline');
        Route::post('conversations/{id}/quotes/{quote}/withdraw', [ChatCommerceController::class, 'withdrawQuote'])
            ->middleware('throttle:chat-decision')->name('conversations.quotes.withdraw');
        Route::post('conversations/{id}/quotes/{quote}/counter', [ChatCommerceController::class, 'counter'])
            ->middleware('throttle:chat-decision')->name('conversations.quotes.counter');

        Route::post('conversations/{id}/counter-offers/{offer}/respond', [ChatCommerceController::class, 'respondToCounter'])
            ->middleware('throttle:chat-decision')->name('conversations.counter-offers.respond');

        // Read-only, so no write throttle: the same structured snapshot the
        // web proforma sheet renders as HTML.
        Route::get('conversations/{id}/orders/{order}/proforma', [ChatOrderController::class, 'proformaSheet'])
            ->name('conversations.orders.proforma.show');

        Route::prefix('conversations/{id}/orders/{order}')->name('conversations.orders.')->middleware('throttle:chat-decision')->group(function (): void {
            Route::post('proforma', [ChatOrderController::class, 'proforma'])->name('proforma');
            Route::post('payment-request', [ChatOrderController::class, 'requestPayment'])->name('payment-request');
            Route::post('payment-record', [ChatOrderController::class, 'recordPayment'])->name('payment-record');
            Route::post('confirm', [ChatOrderController::class, 'confirm'])->name('confirm');
            Route::post('production', [ChatOrderController::class, 'startProduction'])->name('production');
            Route::post('ship', [ChatOrderController::class, 'ship'])->name('ship');
            Route::post('tracking', [ChatOrderController::class, 'updateTracking'])->name('tracking');
            Route::post('deliver', [ChatOrderController::class, 'deliver'])->name('deliver');
            Route::post('complete', [ChatOrderController::class, 'complete'])->name('complete');
            Route::post('review', [ChatOrderController::class, 'review'])
                ->middleware('throttle:order-review')->name('review');
        });

        // Uploads get their own tighter bucket — same reasoning as the web
        // route: they cost disk, not just rows.
        Route::post('conversations/{id}/orders/{order}/documents', [ChatOrderController::class, 'attachDocuments'])
            ->middleware('throttle:order-upload')->name('conversations.orders.documents');

        // Streams through the SAME participation rule as the web download
        // controller (buyer OR a member of the supplying company), reached
        // via the conversation instead of a signed web link — see
        // ChatOrderController::downloadDocument()'s docblock.
        Route::get('conversations/{id}/orders/{order}/documents/{document}/download', [ChatOrderController::class, 'downloadDocument'])
            ->name('conversations.orders.documents.download');

        // Supplier prices a reorder request, keyed by the reorder RFQ rather
        // than an order — mirrors `chat.reorder.quote` on the web.
        Route::post('conversations/{id}/reorders/{rfq}/quote', [ChatOrderController::class, 'reorderQuote'])
            ->middleware('throttle:chat-decision')->name('conversations.reorders.quote');

        // Any authenticated user's own notification center (quote received,
        // order status changed, message received, dispute reply). Own
        // `notifications` prefix, deliberately placed right after the
        // conversation group rather than inside it.
        Route::prefix('notifications')->name('notifications.')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
            Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
            Route::get('preferences', [NotificationPreferenceController::class, 'show'])->name('preferences.show');
            Route::patch('preferences', [NotificationPreferenceController::class, 'update'])->name('preferences.update');
            Route::get('{id}', [NotificationController::class, 'show'])->name('show');
            Route::post('{id}/read', [NotificationController::class, 'read'])->name('read');
        });

        // Expo push-token registration for the mobile app — own prefix,
        // right after `notifications` for the same reason that group sits
        // where it does (any-authenticated-user, not buyer/supplier-scoped).
        Route::prefix('devices')->name('devices.')->group(function (): void {
            Route::post('/', [DeviceTokenController::class, 'store'])->name('store');
            Route::delete('{token}', [DeviceTokenController::class, 'destroy'])->name('destroy');
        });
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

        // Reorder (buyer-initiated only): re-enters the audited RFQ -> triage
        // -> quote -> accept path via ReorderService, never clones the order
        // directly. Same buyer-owns-the-order boundary as every route above,
        // via BuyerApiScope::order(). See ReorderController's docblock for the
        // eligibility-is-always-200 and idempotency decisions.
        Route::get('orders/{orderReference}/reorder', [ReorderController::class, 'eligibility'])
            ->name('orders.reorder.eligibility');

        Route::post('orders/{orderReference}/reorder', [ReorderController::class, 'store'])
            ->middleware('throttle:api-decision')->name('orders.reorder.store');

        // Order review (buyer-initiated only): the buyer's own review of the
        // supplier on a completed order, via CompanyReviewService — the same
        // one-per-order rule and `isReviewable()` eligibility the web form
        // already enforces. Same buyer-owns-the-order boundary as every route
        // above, via BuyerApiScope::order(). See CompanyReviewController's
        // docblock for the eligibility-is-always-200 decision.
        Route::get('orders/{orderReference}/review', [CompanyReviewController::class, 'eligibility'])
            ->name('orders.review.eligibility');

        Route::post('orders/{orderReference}/review', [CompanyReviewController::class, 'store'])
            ->middleware('throttle:api-decision')->name('orders.review.store');

        // Receipts (this task): the buyer's own live (non-voided) receipts,
        // read-only, API counterpart of `/account/receipts`. Same
        // buyer-owns-the-underlying-order boundary as everything else in
        // this group, via BuyerApiScope::receipts()/receipt(). See
        // ReceiptController's docblock for why there is no download/PDF
        // route alongside these two.
        Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::get('receipts/{receiptNumber}', [ReceiptController::class, 'show'])->name('receipts.show');
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

        // The caller's own company's verification status (this task) — API
        // counterpart of the exporter panel's VerificationStatusWidget.
        Route::get('company/verification', CompanyVerificationController::class)
            ->name('company.verification');

        // The caller's own company profile (this task) — API counterpart of
        // the exporter panel's "Edit company" form. Siblings of
        // `company/verification` above, same middleware.
        Route::get('company', [CompanyProfileController::class, 'show'])
            ->name('company.show');
        Route::patch('company', [CompanyProfileController::class, 'update'])
            ->middleware('throttle:api-decision')->name('company.update');

        // The caller's own company onboarding checklist (this task) — API
        // counterpart of the exporter panel's OnboardingChecklist page.
        Route::get('company/onboarding', [CompanyOnboardingController::class, 'index'])
            ->name('company.onboarding');

        // RFQ inbox (this task): RFQs routed to the caller's company. Lives
        // under a `supplier/` sub-prefix — NOT bare `rfqs`/`orders` — so it
        // never collides with the buyer group's identically-named routes at
        // `v1/rfqs`/`v1/orders` above (both groups share the `v1` prefix;
        // Laravel resolves routes by URI+method first and would silently
        // always match the buyer route otherwise, regardless of middleware).
        // See SupplierRfqController's docblock for what stands in for a
        // dedicated Filament "RFQ inbox" resource on the web today.
        Route::prefix('supplier')->name('supplier.')->group(function (): void {
            Route::get('rfqs', [SupplierRfqController::class, 'index'])->name('rfqs.index');
            Route::get('rfqs/{reference}', [SupplierRfqController::class, 'show'])->name('rfqs.show');

            // Quote submission (this task): wraps QuoteService::open()/submit(),
            // the exact same write path the exporter "Create Quote" form uses.
            Route::post('rfqs/{reference}/quote', [SupplierQuoteController::class, 'store'])
                ->middleware('throttle:api-decision')->name('rfqs.quote.store');

            // The supplier's own submitted quotes across all RFQs (this
            // task) — distinct from the buyer-scoped
            // GET /rfqs/{reference}/quotes above.
            Route::get('quotes', [SupplierQuoteController::class, 'index'])->name('quotes.index');

            // The supplier's own sales orders (this task): orders where the
            // caller's company is the SUPPLIER side. See SupplierOrderController.
            Route::get('orders', [SupplierOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{reference}', [SupplierOrderController::class, 'show'])->name('orders.show');

            // Product management (this task): full CRUD + submit-for-publish
            // over the caller's own company's catalogue listings, API
            // counterpart of `Filament\Exporter\Resources\Products\ProductResource`
            // — see SupplierProductController's docblock. `options` must be
            // registered before `{product}` so it is never swallowed by the
            // wildcard show route.
            Route::get('products/options', [SupplierProductController::class, 'options'])->name('products.options');
            Route::get('products', [SupplierProductController::class, 'index'])->name('products.index');
            Route::post('products', [SupplierProductController::class, 'store'])
                ->middleware('throttle:api-rfq')->name('products.store');
            Route::get('products/{product}', [SupplierProductController::class, 'show'])->name('products.show');
            Route::patch('products/{product}', [SupplierProductController::class, 'update'])
                ->middleware('throttle:api-decision')->name('products.update');
            Route::post('products/{product}/submit', [SupplierProductController::class, 'submit'])
                ->middleware('throttle:api-decision')->name('products.submit');
            Route::delete('products/{product}', [SupplierProductController::class, 'destroy'])
                ->middleware('throttle:api-decision')->name('products.destroy');

            // Product photo upload (this task): mirrors the ONE real image
            // field the web form has (`primary_image_path`). No
            // gallery add/delete/reorder routes exist — see
            // SupplierProductImageController's docblock for why.
            Route::post('products/{product}/images', [SupplierProductImageController::class, 'store'])
                ->middleware('throttle:api-rfq')->name('products.images.store');

            // Fleet (this task): vehicles + drivers, API counterpart of the
            // exporter panel's Vehicles/Drivers resources. Sits under
            // `api.supplier` like the rest of this group (company membership
            // is the auth boundary), but every action additionally goes
            // through FleetApiScope::ensureEligible() — a company that isn't
            // OrganisationType::Logistics (and no logistics_partner member)
            // gets a 403 here, mirroring the web resource's
            // canViewAny/canCreate/canEdit/canDelete gate exactly. See
            // FleetApiScope's docblock.
            Route::prefix('fleet')->name('fleet.')->group(function (): void {
                Route::get('vehicles', [FleetVehicleController::class, 'index'])->name('vehicles.index');
                Route::post('vehicles', [FleetVehicleController::class, 'store'])
                    ->middleware('throttle:api-rfq')->name('vehicles.store');
                Route::get('vehicles/{vehicle}', [FleetVehicleController::class, 'show'])->name('vehicles.show');
                Route::patch('vehicles/{vehicle}', [FleetVehicleController::class, 'update'])
                    ->middleware('throttle:api-decision')->name('vehicles.update');

                Route::get('drivers', [FleetDriverController::class, 'index'])->name('drivers.index');
                Route::post('drivers', [FleetDriverController::class, 'store'])
                    ->middleware('throttle:api-rfq')->name('drivers.store');
                Route::get('drivers/{driver}', [FleetDriverController::class, 'show'])->name('drivers.show');
                Route::patch('drivers/{driver}', [FleetDriverController::class, 'update'])
                    ->middleware('throttle:api-decision')->name('drivers.update');
            });
        });
    });
});
