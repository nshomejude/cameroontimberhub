<?php

use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\OrderDocumentDownloadController;
use App\Http\Controllers\Public\AccountController;
use App\Http\Controllers\Public\BuyerOrderController;
use App\Http\Controllers\Public\BuyerQuoteController;
use App\Http\Controllers\Public\ChatCommerceController;
use App\Http\Controllers\Public\CompanyController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\DirectoryController;
use App\Http\Controllers\Public\GlossaryController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\InquiryController;
use App\Http\Controllers\Public\InsightController;
use App\Http\Controllers\Public\KnowledgeController;
use App\Http\Controllers\Public\MessageController;
use App\Http\Controllers\Public\MobileAppController;
use App\Http\Controllers\Public\OrderLifecycleController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PricingController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Controllers\Public\ProgrammaticExporterController;
use App\Http\Controllers\Public\ReceiptVerificationController;
use App\Http\Controllers\Public\ReorderController;
use App\Http\Controllers\Public\RfqController;
use App\Http\Controllers\Public\RfqListController;
use App\Http\Controllers\Public\SearchController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\SpeciesController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Directory & company profiles (static segments before slug routes).
Route::get('/companies', [DirectoryController::class, 'index'])->name('directory');
Route::get('/companies/{slug}', [CompanyController::class, 'show'])->name('companies.show');

// Product marketplace (static segment before the CMS slug catch-all).
Route::get('/marketplace', [ProductController::class, 'index'])->name('marketplace');
Route::get('/marketplace/{product:slug}', [ProductController::class, 'show'])->name('products.show');

// Cross-entity search (products + companies + species).
Route::get('/search', [SearchController::class, 'index'])->name('search');

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

// Public RFQ intake (no login) + email verification.
Route::get('/request-quote', [RfqController::class, 'create'])->name('rfq.create');
Route::post('/request-quote', [RfqController::class, 'store'])->middleware('throttle:rfq-submit')->name('rfq.store');
Route::get('/request-quote/thanks', [RfqController::class, 'thanks'])->name('rfq.thanks');
// Wizard steps. Each is a real GET URL so refresh and browser back/forward work
// without JavaScript; the POST banks the step in the session and redirects.
Route::get('/request-quote/step/{step}', [RfqController::class, 'step'])->name('rfq.step');
Route::post('/request-quote/step/{step}', [RfqController::class, 'storeStep'])
    ->middleware('throttle:rfq-step')->name('rfq.step.store');

// Session-backed RFQ shortlist ("Add to RFQ List" on a product page).
Route::post('/rfq-list/{slug}', [RfqListController::class, 'store'])->name('rfq-list.store');
Route::delete('/rfq-list/{slug}', [RfqListController::class, 'destroy'])->name('rfq-list.destroy');
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

// Public company inquiry intake + email verification.
Route::post('/companies/{company:slug}/inquiries', [InquiryController::class, 'store'])->middleware('throttle:inquiry-submit')->name('inquiry.store');
Route::get('/inquiry/{inquiry}/verify', [InquiryController::class, 'verify'])->middleware('signed')->name('inquiry.verify');

// Static marketing pages (CMS-backed via the pages table).
Route::get('/about', [PageController::class, 'show'])->defaults('slug', 'about')->name('about');
Route::get('/verification', [PageController::class, 'show'])->defaults('slug', 'verification')->name('verification.info');
Route::get('/list-your-company', [PageController::class, 'show'])->defaults('slug', 'list-your-company')->name('list.company');

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

// CMS catch-all — must be last. Resolves any published page by slug (legal, static, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')
    ->name('page.show');
