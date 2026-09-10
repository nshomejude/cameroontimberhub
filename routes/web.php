<?php

use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CompliancePackController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\OrderDocumentDownloadController;
use App\Http\Controllers\Public\AccountController;
use App\Http\Controllers\Public\BuyerOrderController;
use App\Http\Controllers\Public\BuyerQuoteController;
use App\Http\Controllers\Public\CarbonProjectsController;
use App\Http\Controllers\Public\CertificateVerificationController;
use App\Http\Controllers\Public\ChatCommerceController;
use App\Http\Controllers\Public\CheckpointTrackingController;
use App\Http\Controllers\Public\CompanyController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\DirectoryController;
use App\Http\Controllers\Public\DisputeController;
use App\Http\Controllers\Public\DomesticMarketplaceController;
use App\Http\Controllers\Public\GlossaryController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\InquiryController;
use App\Http\Controllers\Public\InsightController;
use App\Http\Controllers\Public\InspectorReportController;
use App\Http\Controllers\Public\KnowledgeController;
use App\Http\Controllers\Public\LogisticsCheckpointController;
use App\Http\Controllers\Public\LogisticsDirectoryController;
use App\Http\Controllers\Public\MadeInCameroonController;
use App\Http\Controllers\Public\MessageController;
use App\Http\Controllers\Public\MobileAppController;
use App\Http\Controllers\Public\OrderLifecycleController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PricingController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Controllers\Public\ProductVerificationController;
use App\Http\Controllers\Public\ProgrammaticExporterController;
use App\Http\Controllers\Public\ReceiptVerificationController;
use App\Http\Controllers\Public\ReorderController;
use App\Http\Controllers\Public\RfqController;
use App\Http\Controllers\Public\RfqListController;
use App\Http\Controllers\Public\SearchController;
use App\Http\Controllers\Public\ShipmentWaybillController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\SpeciesController;
use App\Http\Controllers\Public\TimberPassportController;
use App\Http\Controllers\Public\TradeAssuranceController;
use App\Http\Controllers\Public\TransformationNetworkController;
use Illuminate\Support\Facades\Route;

// Richer JSON readiness probe. Laravel's default `/up` (bootstrap/app.php
// withRouting health) stays as the trivial liveness check; this adds the
// database/cache/queue readiness detail for load balancers and uptime monitors.
Route::get('/up/health', \App\Http\Controllers\HealthController::class)->name('health');

// Content-Security-Policy violation collector (production-readiness Task A3).
// Unauthenticated and CSRF-exempt (see bootstrap/app.php validateCsrfTokens) —
// a CSP report is a token-less browser beacon. Logs to the `errors` channel and
// never 500s on a malformed body. Rate-limited generously (browsers batch).
Route::post('/csp-report', \App\Http\Controllers\CspReportController::class)
    ->middleware('throttle:csp-report')
    ->name('csp.report');

Route::get('/', [HomeController::class, 'index'])->name('home');

// Directory & company profiles (static segments before slug routes).
Route::get('/companies', [DirectoryController::class, 'index'])->name('directory');
Route::get('/companies/{slug}', [CompanyController::class, 'show'])->name('companies.show');
Route::get('/companies/{slug}/portfolio', [CompanyController::class, 'portfolio'])->name('companies.portfolio');

// Transformation Network: processor/manufacturer directory (gap-plan 1.5.3), separate from /companies.
Route::get('/transformation-network', [TransformationNetworkController::class, 'index'])->name('transformation-network');
Route::get('/transformation-network/match', [TransformationNetworkController::class, 'match'])->name('transformation-network.match');

// Logistics Directory: logistics/transport company directory (gap-plan 1.5.9), separate from /companies.
Route::get('/logistics-directory', [LogisticsDirectoryController::class, 'index'])->name('logistics-directory');

// Product marketplace (static segment before the CMS slug catch-all).
Route::get('/marketplace', [ProductController::class, 'index'])->name('marketplace');
Route::get('/marketplace/{product:slug}', [ProductController::class, 'show'])->name('products.show');

// Domestic-market search — never requires export vocabulary (gap-plan 1.5.2).
Route::get('/buy-cameroon-wood', [DomesticMarketplaceController::class, 'index'])->name('domestic.marketplace');

// "Made in Cameroon" badge landing page (gap-plan 1.5.7).
Route::get('/made-in-cameroon', [MadeInCameroonController::class, 'index'])->name('made-in-cameroon');

// Public carbon-project directory: carbon-developer companies' self-posted projects.
Route::get('/carbon-projects', [CarbonProjectsController::class, 'index'])->name('carbon-projects');
Route::get('/carbon-projects/{carbonProject}', [CarbonProjectsController::class, 'show'])->name('carbon-projects.show');
Route::get('/passport/{timberLot}', [TimberPassportController::class, 'show'])->name('passport.show');

// Cross-entity search (products + companies + species).
Route::get('/search', [SearchController::class, 'index'])->name('search');

// Language switcher (header "EN/Français" button) — stores the choice in
// session; App\Http\Middleware\SetLocale reads it back on every request.
Route::post('/locale/{locale}', function (\Illuminate\Http\Request $request, string $locale) {
    abort_unless(in_array($locale, \App\Http\Middleware\SetLocale::SUPPORTED, true), 404);

    $request->session()->put('locale', $locale);

    return back();
})->middleware('throttle:session-write')->name('locale.set');

// Pricing (plans-as-data).
Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');

// Buyer mobile app marketing page + "notify me at launch" capture. Static
// segments, so declared well before the CMS `/{slug}` catch-all.
Route::get('/mobile-app', [MobileAppController::class, 'show'])->name('mobile.app');
Route::post('/mobile-app/notify', [MobileAppController::class, 'subscribe'])
    ->middleware('throttle:app-notify')->name('mobile.app.notify');

// Species catalog + programmatic-SEO species pages.
Route::get('/species', [SpeciesController::class, 'index'])->name('species.index');
Route::get('/species/{slug}', [SpeciesController::class, 'show'])->name('species.show');

// Knowledge: the Cameroon timber glossary. A flat dictionary — the static
// index segment is declared before the slug route.
Route::get('/knowledge/glossary', [GlossaryController::class, 'index'])->name('glossary.index');
Route::get('/knowledge/glossary/{slug}', [GlossaryController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')->name('glossary.show');

// Knowledge Centre — the landing page, the eleven hub pillars and evergreen
// articles at their hub URLs. Declared after the glossary routes so the static
// `glossary` segment always wins over the `{hub}` placeholder.
Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
Route::get('/knowledge/{hub}', [KnowledgeController::class, 'hub'])
    ->where('hub', '[a-z0-9][a-z0-9-]*')->name('knowledge.hub');
Route::get('/knowledge/{hub}/{slug}', [InsightController::class, 'hubArticle'])
    ->where(['hub' => '[a-z0-9][a-z0-9-]*', 'slug' => '[a-z0-9][a-z0-9-]*'])
    ->name('knowledge.article');

// Editorial content platform. `/insights/category/{category}` is declared
// before `/insights/{slug}` so the static segment always wins.
Route::get('/insights', [InsightController::class, 'index'])->name('insights.index');
Route::get('/insights/category/{category}', [InsightController::class, 'category'])
    ->where('category', '[a-z-]+')->name('insights.category');
Route::get('/insights/{slug}', [InsightController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')->name('insights.show');

// SEO infrastructure.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
// AEO: a plain-text map of the site's substantive content for language models.
Route::get('/llms.txt', [SitemapController::class, 'llms'])->name('llms');

// Signed, auth-gated private compliance document download.
Route::get('/documents/{document}/download', DocumentDownloadController::class)
    ->name('documents.download')
    ->middleware(['signed', 'auth']);

// Auth-gated CTH Compliance Evidence Pack download (blueprint §83) — the lot's owning company or compliance.export staff only, never public.
Route::get('/compliance-pack/{timberLot}', [CompliancePackController::class, 'download'])->middleware('auth')->name('compliance-pack.download');

// Public RFQ intake (no login) + email verification.
Route::get('/request-quote', [RfqController::class, 'create'])->name('rfq.create');
Route::get('/request-quote/manufacturing', [RfqController::class, 'createManufacturing'])->name('rfq.create.manufacturing');
Route::get('/request-quote/transport', [RfqController::class, 'createTransport'])->name('rfq.create.transport');
Route::post('/request-quote', [RfqController::class, 'store'])->middleware('throttle:rfq-submit')->name('rfq.store');
Route::get('/request-quote/thanks', [RfqController::class, 'thanks'])->name('rfq.thanks');
// Wizard steps. Each is a real GET URL so refresh and browser back/forward work
// without JavaScript; the POST banks the step in the session and redirects.
Route::get('/request-quote/step/{step}', [RfqController::class, 'step'])->name('rfq.step');
Route::post('/request-quote/step/{step}', [RfqController::class, 'storeStep'])
    ->middleware('throttle:rfq-step')->name('rfq.step.store');

// Session-backed RFQ shortlist ("Add to RFQ List" on a product page).
Route::post('/rfq-list/{slug}', [RfqListController::class, 'store'])->middleware('throttle:session-write')->name('rfq-list.store');
Route::delete('/rfq-list/{slug}', [RfqListController::class, 'destroy'])->middleware('throttle:session-write')->name('rfq-list.destroy');
Route::get('/rfq/{rfq}/verify', [RfqController::class, 'verify'])->middleware('signed')->name('rfq.verify');

// Buyer-facing quote responses. Deliberately NOT behind `auth` or `signed`
// middleware: RFQ intake is account-free, so access is decided per-request by
// BuyerRfqAccess, which admits either a valid long-lived signed link or the
// signed-in owner of the RFQ (rfqs.user_id). Accept/decline are POSTs only.
Route::get('/rfq/{rfq}/responses', [BuyerQuoteController::class, 'index'])->name('buyer.rfq.responses');
Route::get('/rfq/{rfq}/responses/{quote}', [BuyerQuoteController::class, 'show'])->name('buyer.rfq.quote');
Route::post('/rfq/{rfq}/responses/{quote}/accept', [BuyerQuoteController::class, 'accept'])
    ->middleware('throttle:12,1')->name('buyer.rfq.quote.accept');
Route::post('/rfq/{rfq}/responses/{quote}/decline', [BuyerQuoteController::class, 'decline'])
    ->middleware('throttle:12,1')->name('buyer.rfq.quote.decline');

// Award & order. Same access model as the quote screens (BuyerRfqAccess).
// The award review page is a GET, but awarding is only ever the accept POST.
Route::get('/rfq/{rfq}/responses/{quote}/award', [BuyerOrderController::class, 'award'])->name('buyer.rfq.quote.award');
Route::get('/rfq/{rfq}/order', [BuyerOrderController::class, 'show'])->name('buyer.rfq.order');
Route::get('/rfq/{rfq}/order/receipt', [BuyerOrderController::class, 'receipt'])->name('buyer.rfq.order.receipt');

// Public receipt verification. Open to anyone by design, so it is throttled
// hard and discloses only ReceiptVerifier::publicPayload().
Route::get('/verify', [ReceiptVerificationController::class, 'create'])->name('receipts.verify');
Route::post('/verify', [ReceiptVerificationController::class, 'store'])
    ->middleware('throttle:receipt-verify')->name('receipts.verify.store');
Route::get('/verify/{token}', [ReceiptVerificationController::class, 'token'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->middleware('throttle:receipt-verify')->name('receipts.verify.token');

// Public certificate verification. Open to anyone by design, so it is
// throttled hard and discloses only CertificateVerifier::publicPayload().
// Note these do not collide with /verify/{token} above: that route's token
// pattern requires 16+ characters, and "certificate" is 11.
Route::get('/verify/certificate', [CertificateVerificationController::class, 'create'])->name('certificates.verify');
Route::get('/verify/certificate/{token}', [CertificateVerificationController::class, 'token'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->middleware('throttle:certificate-verify')->name('certificates.verify.token');

// Public product-listing verification (gap-plan 1.1). Bound explicitly by
// public_id — Product::getRouteKeyName() stays `slug`. No collision with
// /verify/{token} (that route requires a 16+ char alnum token) or
// /verify/certificate (two segments).
Route::get('/verify/product/{publicId}', [ProductVerificationController::class, 'show'])
    ->middleware('throttle:product-verify')->name('products.verify');

// Public carbon project registry verification (§2.6). Bound by public_id
// (`CTH-CARB-00000`); only registered/active projects resolve, others 404.
Route::get('/verify/carbon/{publicId}', [\App\Http\Controllers\Public\CarbonProjectVerificationController::class, 'show'])
    ->middleware('throttle:carbon-verify')->name('carbon.verify');

// Public checkpoint tracking (gap-plan 1.5.11). Open to anyone holding the
// link; throttled like /verify and /verify/certificate above.
Route::get('/track/{token}', [CheckpointTrackingController::class, 'show'])
    ->middleware('throttle:checkpoint-track')->name('checkpoints.track');

// Public digital waybill (gap-plan 1.5.10). No auth — a printed/scanned
// waybill must work for a checkpoint officer or receiving clerk with no
// account. Looked up by the unguessable waybill_number token, not the id.
Route::get('/shipments/{shipment:waybill_number}/waybill', [ShipmentWaybillController::class, 'show'])
    ->name('shipments.waybill.show');

// Offline-capable field checkpoint capture for logistics/drivers (blueprint
// §45-46). Same no-auth, waybill_number-token access pattern as the waybill
// route directly above — a driver in the field has no account either.
Route::get('/logistics/shipments/{shipment:waybill_number}/checkpoint', [LogisticsCheckpointController::class, 'create'])
    ->name('logistics.checkpoints.create');
Route::post('/logistics/shipments/{shipment:waybill_number}/checkpoint', [LogisticsCheckpointController::class, 'store'])
    ->middleware('throttle:checkpoint-record')->name('logistics.checkpoints.store');

// The staff-facing printable certificate document. Authorization is checked
// inside the controller against the certificates.manage permission.
Route::get('/certificates/{certificateNumber}', [CertificateVerificationController::class, 'show'])
    ->middleware('auth')->name('certificates.show');

// Public company inquiry intake + email verification.
Route::post('/companies/{company:slug}/inquiries', [InquiryController::class, 'store'])->middleware('throttle:inquiry-submit')->name('inquiry.store');
Route::get('/inquiry/{inquiry}/verify', [InquiryController::class, 'verify'])->middleware('signed')->name('inquiry.verify');

// Static marketing pages (CMS-backed via the pages table).
Route::get('/about', [PageController::class, 'show'])->defaults('slug', 'about')->name('about');
Route::get('/verification', [PageController::class, 'show'])->defaults('slug', 'verification')->name('verification.info');
Route::get('/list-your-company', [PageController::class, 'show'])->defaults('slug', 'list-your-company')->name('list.company');
Route::get('/how-it-works', [PageController::class, 'show'])->defaults('slug', 'how-it-works')->name('how-it-works');

// Legal pages (CMS-backed, template=legal). Linked from the footer, the
// register-page consent checkboxes, and the account layout footer — all of
// which were previously 404ing because no route or Page row existed.
Route::get('/terms', [PageController::class, 'show'])->defaults('slug', 'terms')->name('terms');
Route::get('/privacy', [PageController::class, 'show'])->defaults('slug', 'privacy')->name('privacy');
Route::get('/cookies', [PageController::class, 'show'])->defaults('slug', 'cookies')->name('cookies');

// Help Center — no dedicated page content yet, so it points at the existing
// Contact page rather than 404ing or duplicating contact-form functionality.
Route::redirect('/help', '/contact', 301)->name('help');

// Contact page — dedicated controller for the POST handler.
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:6,1')->name('contact.store');

// SEO landing pages for primary keywords (landing template, shows company grid).
Route::get('/timber-exporters-cameroon', [PageController::class, 'show'])->defaults('slug', 'timber-exporters-cameroon')->name('seo.exporters');
Route::get('/cameroon-timber-suppliers', [PageController::class, 'show'])->defaults('slug', 'cameroon-timber-suppliers')->name('seo.suppliers');

// Programmatic SEO: /exporters/{species}-cameroon — 404s when no verified companies handle the species.
Route::get('/exporters/{species}', [ProgrammaticExporterController::class, 'show'])
    ->where('species', '[a-z0-9-]+-cameroon')
    ->name('pseo.exporters');

// Public buyer/supplier authentication (hand-rolled; no starter kit).
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
    // One-click demo logins. POST-only and CSRF-protected on purpose: a GET
    // would let a link, a prefetch or a crawler authenticate someone. The
    // persona segment is constrained to the three literal keys in
    // config('demo.personas') at the route level as well as in the controller,
    // and the whole feature 404s unless the DemoLoginsEnabled Pennant flag is
    // active (seeded from DEMO_LOGINS_ENABLED, but an admin activate/deactivate
    // call always wins after the first resolution — see
    // App\Features\DemoLoginsEnabled and the demo.logins.enabled middleware).
    Route::post('/demo-login/{persona}', DemoLoginController::class)
        ->where('persona', 'buyer|supplier|admin')
        ->middleware(['throttle:demo-login', 'demo.logins.enabled'])
        ->name('demo.login');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1')->name('register.store');

    // Password reset (standard Laravel broker, hand-rolled controllers).
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// TOTP-based multi-factor authentication (blueprint §39). Self-service
// enrolment/management is available to every signed-in user; the step-up
// re-verify challenge is used both by RequiresRecentTwoFactor-gated routes
// and, out-of-band, by the Filament revocation-approval action.
Route::middleware(['auth'])->prefix('security/two-factor')->name('two-factor.')->group(function () {
    Route::get('/', [App\Http\Controllers\Auth\TwoFactorController::class, 'show'])->name('show');
    Route::post('/enable', [App\Http\Controllers\Auth\TwoFactorController::class, 'enable'])->name('enable');
    Route::post('/confirm', [App\Http\Controllers\Auth\TwoFactorController::class, 'confirm'])->name('confirm');
    Route::post('/disable', [App\Http\Controllers\Auth\TwoFactorController::class, 'disable'])->name('disable');
    Route::post('/recovery-codes', [App\Http\Controllers\Auth\TwoFactorController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
    Route::get('/challenge', [App\Http\Controllers\Auth\TwoFactorController::class, 'showChallenge'])->name('challenge.show');
    Route::post('/challenge', [App\Http\Controllers\Auth\TwoFactorController::class, 'challenge'])
        ->middleware('throttle:6,1')->name('challenge.store');
});

// Fleet & driver registry (gap-plan item 1.5.12) is managed in the exporter
// panel — App\Filament\Exporter\Resources\Vehicles + \Drivers, company-scoped
// like every other exporter resource. The old standalone /fleet Blade page
// was replaced so company data lives in one consistent place.

// Formal Dispute Resolution workflow (blueprint §64). Reachable by either
// party to the order — buyer or supplier company member — so it lives
// outside the buyer-only `/account` group. DisputeController re-derives
// party membership from the order itself on every request.
Route::middleware(['auth'])->prefix('orders/{order}/disputes')->name('disputes.')->group(function () {
    Route::get('/', [DisputeController::class, 'index'])->name('index');
    Route::post('/', [DisputeController::class, 'store'])->name('store');
    Route::get('/{dispute}', [DisputeController::class, 'show'])->name('show');
    Route::post('/{dispute}/evidence', [DisputeController::class, 'submitEvidence'])->name('evidence');
    Route::post('/{dispute}/reply', [DisputeController::class, 'reply'])->name('reply');
    Route::post('/{dispute}/appeal', [DisputeController::class, 'appeal'])->name('appeal');
});

// Offline-capable inspector field-capture form (blueprint §45-46). Plain
// Blade (no Livewire) so a plain fetch/form POST can be intercepted by
// resources/js/offline-queue.js's OfflineQueue when the inspector is
// offline at a timber site. InspectorReportController re-derives, on every
// request, that the signed-in user is the specific Inspector assigned to
// this Inspection — never trusted from the route alone.
Route::middleware(['auth'])->prefix('inspector/inspections/{inspection}')->name('inspector.inspections.')->group(function () {
    Route::get('/report', [InspectorReportController::class, 'edit'])->name('report.edit');
    Route::post('/report', [InspectorReportController::class, 'store'])->name('report.store');
});

// Buyer account area. `/dashboard` is the Filament exporter panel and `/admin`
// the staff panel, so the buyer's own home lives at `/account`. `auth` bounces
// guests to login preserving the intended URL; `buyer` (EnsureBuyerAccount)
// forwards staff and company members to their own panels rather than showing
// them a structurally empty page. Every listing is scoped to the signed-in
// buyer inside BuyerDashboard — no id is ever read from the request.
Route::middleware(['auth', 'buyer'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'index'])->name('index');
    Route::get('/requests', [AccountController::class, 'rfqs'])->name('rfqs');
    Route::get('/quotes', [AccountController::class, 'quotes'])->name('quotes');
    Route::get('/orders', [AccountController::class, 'orders'])->name('orders');
    Route::get('/receipts', [AccountController::class, 'receipts'])->name('receipts');

    // Messaging. `/messages/new` and `/messages/start` are declared before the
    // `{conversation}` binding so the static segments win. Every screen resolves
    // the thread through MessagingService, which 404s a non-participant.
    Route::get('/messages', [MessageController::class, 'index'])->name('messages');
    Route::get('/messages/new', [MessageController::class, 'create'])->name('messages.create');
    Route::post('/messages/start', [MessageController::class, 'start'])
        ->middleware('throttle:message-start')->name('messages.start');
    Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages/{conversation}', [MessageController::class, 'store'])
        ->middleware('throttle:message-send')->name('messages.store');

    // Trade Assurance Phase 1 (blueprint §28) — coordination/tracking only,
    // never fund custody. TradeAssuranceController re-checks server-side that
    // the signed-in user is this order's buyer account before showing or
    // acting on anything; the route binding alone never authorises access.
    Route::get('/orders/{order}/trade-assurance', [TradeAssuranceController::class, 'show'])
        ->name('orders.trade-assurance');
    Route::post('/orders/{order}/trade-assurance/{milestone}/confirm', [TradeAssuranceController::class, 'confirm'])
        ->name('orders.trade-assurance.confirm');
});

// In-thread commerce: RFQ composer, quotation accept/decline/withdraw, and the
// negotiation ledger.
//
// Deliberately outside the `buyer` group: a supplier is a legitimate actor on
// half of these (issuing, withdrawing, countering) and EnsureBuyerAccount would
// bounce them to their own panel before the action ran. The *role* rule is not
// a middleware concern anyway — which side of a given thread you are on is a
// property of that thread, so ChatCommerceService decides it, and a
// non-participant never gets that far because MessagingService 404s first.
//
// Every route is POST. There is no GET that changes state anywhere in here.
Route::middleware(['auth'])->prefix('messages')->name('chat.')->group(function () {
    Route::post('/{conversation}/rfq', [ChatCommerceController::class, 'storeRfq'])
        ->middleware('throttle:chat-rfq')->name('rfq');

    Route::post('/{conversation}/quotes/{quote}/accept', [ChatCommerceController::class, 'acceptQuote'])
        ->middleware('throttle:chat-decision')->name('quote.accept');
    Route::post('/{conversation}/quotes/{quote}/decline', [ChatCommerceController::class, 'declineQuote'])
        ->middleware('throttle:chat-decision')->name('quote.decline');
    Route::post('/{conversation}/quotes/{quote}/withdraw', [ChatCommerceController::class, 'withdrawQuote'])
        ->middleware('throttle:chat-decision')->name('quote.withdraw');
    Route::post('/{conversation}/quotes/{quote}/counter', [ChatCommerceController::class, 'counter'])
        ->middleware('throttle:chat-decision')->name('quote.counter');

    Route::post('/{conversation}/counter-offers/{offer}/respond', [ChatCommerceController::class, 'respondToCounter'])
        ->middleware('throttle:chat-decision')->name('counter.respond');

    /*
     * ---------------------------------------------- Phase 3: order lifecycle
     *
     * Every one of these is POST and CSRF-protected. There is deliberately no
     * GET that advances an order, records a payment, attaches a document or
     * publishes a review — a prefetch or a crawler must never be able to move
     * money-adjacent state.
     *
     * The buyer/supplier split is enforced in OrderLifecycleService, not by
     * middleware, for the same reason the quotation routes are: which side of
     * a thread you are on is a property of that thread, and a stranger 404s in
     * MessagingService long before any role is considered.
     */
    /*
     * The printable proforma invoice sheet. GET, because it changes nothing:
     * it is a read-only document view of the order snapshot, rendered with the
     * same @media print machinery as the receipt. Participation is re-checked
     * inside the controller, so a stranger 404s.
     */
    Route::get('/{conversation}/orders/{order}/proforma', [OrderLifecycleController::class, 'proformaSheet'])
        ->name('order.proforma.sheet');

    Route::prefix('/{conversation}/orders/{order}')->name('order.')->middleware('throttle:chat-decision')->group(function () {
        // supplier-only
        Route::post('/proforma', [OrderLifecycleController::class, 'proforma'])->name('proforma');
        Route::post('/payment-request', [OrderLifecycleController::class, 'requestPayment'])->name('payment.request');
        Route::post('/payment-record', [OrderLifecycleController::class, 'recordPayment'])->name('payment.record');
        Route::post('/confirm', [OrderLifecycleController::class, 'confirm'])->name('confirm');
        Route::post('/production', [OrderLifecycleController::class, 'startProduction'])->name('production');
        Route::post('/ship', [OrderLifecycleController::class, 'ship'])->name('ship');
        Route::post('/tracking', [OrderLifecycleController::class, 'updateTracking'])->name('tracking');
        Route::post('/deliver', [OrderLifecycleController::class, 'deliver'])->name('deliver');

        // Uploads get their own tighter bucket — they cost disk, not just rows.
        Route::post('/documents', [OrderLifecycleController::class, 'attachDocuments'])
            ->middleware('throttle:order-upload')->name('documents');

        // buyer-only
        Route::post('/complete', [OrderLifecycleController::class, 'complete'])->name('complete');
        Route::post('/review', [OrderLifecycleController::class, 'review'])
            ->middleware('throttle:order-review')->name('review');

        /*
         * -------------------------------------------------- Phase 4: reorder
         *
         * Buyer-only, POST, CSRF, and on its own tight bucket because each one
         * writes an RFQ and sends mail — the same reasoning as `chat-rfq`. It
         * is nested under the source order because that order is exactly what
         * authorises it: you may reorder the orders you own, and nothing else.
         */
        Route::post('/reorder', [ReorderController::class, 'store'])
            ->middleware('throttle:chat-reorder')->name('reorder');
    });

    // Supplier prices a reorder request. Keyed by the reorder RFQ rather than
    // by an order, because at this point no new order exists yet — which is
    // precisely the guarantee this phase rests on.
    Route::post('/{conversation}/reorders/{rfq}/quote', [ReorderController::class, 'quote'])
        ->middleware('throttle:chat-decision')->name('reorder.quote');
});

// Private order documents (proof of delivery, shipping papers). Auth-gated and
// then authorised against the order itself; the bytes live on the private
// `documents` disk and have no public URL of any kind.
Route::get('/order-documents/{document}/download', OrderDocumentDownloadController::class)
    ->middleware(['auth'])
    ->name('order-documents.download');

// Payment gateway routes (checkout entry point + one file per provider's
// own webhook/callback route) — see routes/payments.php.
require __DIR__.'/payments.php';

// CMS catch-all — must be last. Resolves any published page by slug (legal, static, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')
    ->name('page.show');
