<?php

use App\Models\Page;

it('does not represent the Cameroon-EU VPA as a currently active licensing framework on the homepage', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('Cameroon has signed a Voluntary Partnership Agreement with the EU, and legality', false);
    $response->assertDontSee('export with full FLEGT and SIGIF II documentation', false);
});

it('clarifies on the homepage FAQ that the VPA has terminated and CTH does not issue FLEGT licences', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('no longer in force', false);
    $response->assertSee('Cameroon Timber Hub does not issue FLEGT licences', false);
});

it('shows a real last-updated date on legal pages', function () {
    $page = Page::where('slug', 'terms')->first();

    if (! $page) {
        $termsData = collect(\Database\Seeders\PageSeeder::PAGES)->firstWhere('slug', 'terms');
        $page = Page::create($termsData);
    }

    $response = $this->get(route('terms'));

    $response->assertOk();
    $response->assertSee('Last updated '.$page->updated_at->format('j F Y'));
});
