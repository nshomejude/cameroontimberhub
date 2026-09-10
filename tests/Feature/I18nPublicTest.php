<?php

use App\Models\Company;
use App\Models\Species;

/**
 * Batch D / Task D1 — public marketing + directory views i18n.
 *
 * Every public page in D1 scope must render in BOTH locales without leaking a
 * raw, untranslated `messages.*` key (which is what `__('messages.foo')` renders
 * when `foo` is missing from the active locale's file).
 */
beforeEach(function () {
    // CMS-managed pages (about / how-it-works / contact) live in the `pages` table.
    $this->seed(\Database\Seeders\PageSeeder::class);

    // A verified, publicly visible company + a species so directory/species
    // pages have real rows to render (cards, filters, exporter lists).
    Company::factory()->count(2)->create([
        'status' => \App\Enums\CompanyStatus::Verified,
    ]);
    Species::factory()->count(2)->create(['is_published' => true]);
});

$publicRoutes = [
    '/',
    '/marketplace',
    '/companies',
    '/species',
    '/request-quote',
    '/about',
    '/how-it-works',
    '/contact',
    '/pricing',
    '/made-in-cameroon',
    '/transformation-network',
    '/transformation-network/match',
    '/logistics-directory',
    '/carbon-projects',
    '/buy-cameroon-wood',
];

dataset('publicRoutes', $publicRoutes);
dataset('locales', ['en', 'fr']);

it('renders every public page in both locales without leaking an untranslated key', function (string $path, string $locale) {
    $response = $this->withSession(['locale' => $locale])->get($path);

    expect($response->status())->toBe(200);
    expect($response->getContent())->not->toContain('messages.');
})->with('publicRoutes')->with('locales');

it('serves a working locale switcher that flips <html lang>', function () {
    $this->get('/')->assertSee('lang="en"', false);

    $this->post(route('locale.set', 'fr'))->assertRedirect();

    $this->get('/')->assertSee('lang="fr"', false);

    // switcher control itself is present
    $this->get('/')->assertSee(route('locale.set', 'en'), false)
        ->assertSee(route('locale.set', 'fr'), false);
});

it('emits hreflang alternates for en and fr on the canonical URL', function () {
    $html = $this->get('/')->getContent();

    expect($html)->toContain('hreflang="en"')
        ->toContain('hreflang="fr"')
        ->toContain('hreflang="x-default"');
});

it('actually translates a known heading per locale', function () {
    $this->withSession(['locale' => 'en'])->get('/species')
        ->assertSee('Timber Species Directory');

    $this->withSession(['locale' => 'fr'])->get('/species')
        ->assertSee('Annuaire des essences de bois');
});
