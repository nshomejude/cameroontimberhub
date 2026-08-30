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
    $this->post(route('locale.set', 'de'))->assertNotFound();

    expect(session('locale'))->toBeNull();
});

it('rejects a non-supported locale like "xx" without setting the session', function () {
    $this->post(route('locale.set', 'xx'))->assertNotFound();

    expect(session()->has('locale'))->toBeFalse();
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
