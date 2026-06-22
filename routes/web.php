<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\Public\CompanyController;
use App\Http\Controllers\Public\DirectoryController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\InquiryController;
use App\Http\Controllers\Public\RfqController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\SpeciesController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Directory & company profiles (static segments before slug routes).
Route::get('/companies', [DirectoryController::class, 'index'])->name('directory');
Route::get('/companies/{slug}', [CompanyController::class, 'show'])->name('companies.show');

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
Route::get('/rfq/{rfq}/verify', [RfqController::class, 'verify'])->middleware('signed')->name('rfq.verify');

// Public company inquiry intake + email verification.
Route::post('/companies/{company:slug}/inquiries', [InquiryController::class, 'store'])->middleware('throttle:inquiry-submit')->name('inquiry.store');
Route::get('/inquiry/{inquiry}/verify', [InquiryController::class, 'verify'])->middleware('signed')->name('inquiry.verify');
