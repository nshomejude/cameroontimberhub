<?php

it('defaults to English on the homepage', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Browse Marketplace', false);
});

it('switches locale to French via the locale route and persists it in session', function () {
    $this->post(route('locale.set', 'fr'))->assertRedirect();

    expect(session('locale'))->toBe('fr');

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Parcourir le march', false); // "Parcourir le marché" (accent-safe substring)
});

it('switches back to English after being set to French', function () {
    $this->post(route('locale.set', 'fr'));
    expect(session('locale'))->toBe('fr');

    $this->post(route('locale.set', 'en'))->assertRedirect();
    expect(session('locale'))->toBe('en');

    $response = $this->get(route('home'));
    $response->assertOk();
    $response->assertSee('Browse Marketplace', false);
});

it('rejects an invalid locale value instead of silently accepting it', function () {
    $this->post(route('locale.set', 'ru'))->assertNotFound();

    expect(session('locale'))->toBeNull();
});

it('rejects a non-supported locale like "xx" without setting the session', function () {
    $this->post(route('locale.set', 'xx'))->assertNotFound();

    expect(session()->has('locale'))->toBeFalse();
});

it('accepts all 8 supported locales via the locale route', function () {
    foreach (App\Http\Middleware\SetLocale::SUPPORTED as $locale) {
        $this->post(route('locale.set', $locale))->assertRedirect();
        expect(session('locale'))->toBe($locale);
    }
});

it('resolves each supported Accept-Language primary subtag, bare and regional', function () {
    $cases = [
        'en' => ['en', 'en-US'],
        'fr' => ['fr', 'fr-FR', 'fr-CA'],
        'zh_CN' => ['zh', 'zh-CN'],
        'th' => ['th', 'th-TH'],
        'vi' => ['vi', 'vi-VN'],
        'it' => ['it', 'it-IT'],
        'es' => ['es', 'es-ES', 'es-MX'],
        'de' => ['de', 'de-DE', 'de-AT', 'de-CH'],
    ];

    foreach ($cases as $expected => $headers) {
        foreach ($headers as $header) {
            $this->withHeaders(['Accept-Language' => $header])
                ->getJson('/api/v1/announcements');

            expect(app()->getLocale())->toBe($expected, "Accept-Language: {$header} should resolve to {$expected}");
        }
    }
});

it('falls back to app.locale for a garbage Accept-Language value', function () {
    $this->withHeaders(['Accept-Language' => 'xx-XX,zz;q=0.9'])
        ->getJson('/api/v1/announcements');

    expect(app()->getLocale())->toBe(config('app.locale', 'en'));
});

it('renders the language switcher with all 8 native-script labels on the homepage', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    foreach (['English', 'Français', '中文', 'ไทย', 'Tiếng Việt', 'Italiano', 'Español', 'Deutsch'] as $label) {
        $response->assertSee($label, false);
    }
});

it('renders the homepage in French with no missing-translation raw keys visible', function () {
    $this->post(route('locale.set', 'fr'));

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('messages.home.', false);
    $response->assertDontSee('messages.footer.', false);
    $response->assertDontSee('messages.nav.', false);
});

it('renders the marketplace page in both locales with no missing-translation raw keys', function () {
    foreach (['en', 'fr'] as $locale) {
        $this->post(route('locale.set', $locale));

        $response = $this->get(route('marketplace'));

        $response->assertOk();
        $response->assertDontSee('messages.marketplace.', false);
    }
});

it('renders the registration page in both locales with no missing-translation raw keys', function () {
    foreach (['en', 'fr'] as $locale) {
        $this->post(route('locale.set', $locale));

        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertDontSee('messages.register.', false);
    }

    $this->post(route('locale.set', 'fr'));
    $this->get(route('register'))->assertSee('Cr', false); // "Créez votre compte"
});

it('renders the pricing page in both locales with no missing-translation raw keys', function () {
    foreach (['en', 'fr'] as $locale) {
        $this->post(route('locale.set', $locale));

        $response = $this->get(route('pricing'));

        $response->assertOk();
        $response->assertDontSee('messages.pricing.', false);
    }

    $this->post(route('locale.set', 'fr'));
    $this->get(route('pricing'))->assertSee('Tarifs', false);
});
