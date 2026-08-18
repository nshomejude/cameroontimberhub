<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\Public\CompanyController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\DirectoryController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\InquiryController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PricingController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Controllers\Public\ProgrammaticExporterController;
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

// Species catalog + programmatic-SEO species pages.
Route::get('/species', [SpeciesController::class, 'index'])->name('species.index');
Route::get('/species/{slug}', [SpeciesController::class, 'show'])->name('species.show');

// SEO infrastructure.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Signed, auth-gated private compliance document download.
Route::get('/documents/{document}/download', DocumentDownloadController::class)
    ->name('documents.download')
    ->middleware(['signed', 'auth']);

// Public RFQ intake (no login) + email verification.
Route::get('/request-quote', [RfqController::class, 'create'])->name('rfq.create');
Route::post('/request-quote', [RfqController::class, 'store'])->middleware('throttle:rfq-submit')->name('rfq.store');
Route::get('/request-quote/thanks', [RfqController::class, 'thanks'])->name('rfq.thanks');

// Session-backed RFQ shortlist ("Add to RFQ List" on a product page).
Route::post('/rfq-list/{slug}', [RfqListController::class, 'store'])->name('rfq-list.store');
Route::delete('/rfq-list/{slug}', [RfqListController::class, 'destroy'])->name('rfq-list.destroy');
Route::get('/rfq/{rfq}/verify', [RfqController::class, 'verify'])->middleware('signed')->name('rfq.verify');

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
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1')->name('register.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// CMS catch-all — must be last. Resolves any published page by slug (legal, static, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')
    ->name('page.show');
