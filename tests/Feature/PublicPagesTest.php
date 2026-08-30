<?php

use App\Models\Company;
use App\Models\Page;
use App\Models\SlugRedirect;
use App\Models\Species;

// ---------------------------------------------------------------------------
// CMS page controller (PageController)
// ---------------------------------------------------------------------------

it('renders a published static CMS page', function () {
    Page::factory()->create([
        'slug' => 'about',
        'title' => 'About Us',
        'h1' => 'Who we are',
        'template' => 'static',
        'is_published' => true,
        'data' => ['blocks' => [['type' => 'paragraph', 'content' => 'Welcome to the about page.']]],
    ]);

    $this->get('/about')
        ->assertOk()
        ->assertSee('Who we are')
        ->assertSee('Welcome to the about page.');
});

it('returns 404 for an unpublished CMS page', function () {
    Page::factory()->create([
        'slug' => 'draft-page',
        'template' => 'static',
        'is_published' => false,
    ]);

    $this->get(route('about'))->assertNotFound(); // no published 'about' row
});

it('renders a landing CMS page with the company grid', function () {
    Page::factory()->create([
        'slug' => 'timber-exporters-cameroon',
        'title' => 'Timber exporters from Cameroon',
        'template' => 'landing',
        'is_published' => true,
        'data' => ['blocks' => []],
    ]);

    Company::factory()->publiclyVisible()->create(['legal_name' => 'Landing Grid Co']);

    $this->get(route('seo.exporters'))
        ->assertOk()
        ->assertSee('Timber exporters from Cameroon')
        ->assertSee('Landing Grid Co');
});

it('renders a legal CMS page via the catch-all route', function () {
    Page::factory()->create([
        'slug' => 'privacy-policy',
        'title' => 'Privacy Policy',
        'h1' => 'Privacy Policy',
        'template' => 'legal',
        'is_published' => true,
        'data' => ['blocks' => [['type' => 'paragraph', 'content' => 'We respect your privacy.']]],
    ]);

    $this->get('/privacy-policy')
        ->assertOk()
        ->assertSee('Privacy Policy')
        ->assertSee('We respect your privacy.');
});

it('returns 404 via catch-all for an unpublished CMS page', function () {
    Page::factory()->create([
        'slug' => 'hidden-page',
        'template' => 'static',
        'is_published' => false,
    ]);

    $this->get('/hidden-page')->assertNotFound();
});

// ---------------------------------------------------------------------------
// Contact page and form submission (ContactController)
// ---------------------------------------------------------------------------

it('renders the contact page when a published contact CMS page exists', function () {
    Page::factory()->create([
        'slug' => 'contact',
        'title' => 'Contact Us',
        'h1' => 'Get in touch',
        'template' => 'static',
        'is_published' => true,
        'data' => ['blocks' => []],
    ]);

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('Get in touch')
        ->assertSee('Send Message');
});

it('accepts a valid contact submission and flashes success', function () {
    Page::factory()->create([
        'slug' => 'contact',
        'title' => 'Contact',
        'template' => 'static',
        'is_published' => true,
    ]);

    $this->post(route('contact.store'), [
        'name' => 'Jane Buyer',
        'email' => 'jane@example.com',
        'category' => 'general',
        'subject' => 'Inquiry about sapele',
        'message' => 'I am looking for a reliable sapele supplier in Cameroon.',
        'consent' => '1',
        'form_rendered_at' => now()->subSeconds(10)->timestamp,
    ])->assertRedirect()->assertSessionHas('contact_sent', true);
});

it('rejects contact form submission with missing required fields', function () {
    $this->post(route('contact.store'), [
        'name' => '',
        'email' => 'not-an-email',
        'subject' => '',
        'message' => 'short',
        'consent' => '0',
    ])->assertSessionHasErrors(['name', 'email', 'subject', 'message', 'consent']);
});

it('silently succeeds when the honeypot is tripped on contact form', function () {
    Mail::fake();

    $this->post(route('contact.store'), [
        'name' => 'Bot',
        'email' => 'bot@spam.com',
        'subject' => 'Buy now',
        'message' => 'Click this link to win a prize, guaranteed!',
        'consent' => '1',
        'website' => 'http://spam.example.com', // honeypot filled
        'form_rendered_at' => now()->subSeconds(5)->timestamp,
    ])->assertRedirect()->assertSessionHas('contact_sent', true);

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Programmatic exporter pages (ProgrammaticExporterController)
// ---------------------------------------------------------------------------

it('renders the programmatic exporter page for a species with verified companies', function () {
    $species = Species::factory()->create(['common_name' => 'Sapele', 'slug' => 'sapele']);
    $company = Company::factory()->publiclyVisible()->create(['legal_name' => 'Sapele Export Co']);
    $company->species()->attach($species);

    $this->get(route('pseo.exporters', 'sapele-cameroon'))
        ->assertOk()
        ->assertSee('Sapele exporters from Cameroon')
        ->assertSee('Sapele Export Co');
});

it('returns 404 on the programmatic exporter page when no verified companies handle that species', function () {
    Species::factory()->create(['common_name' => 'Rarewood', 'slug' => 'rarewood']);

    // No companies attached to rarewood.
    $this->get(route('pseo.exporters', 'rarewood-cameroon'))->assertNotFound();
});

it('returns 404 on the programmatic exporter page for an unknown species', function () {
    $this->get(route('pseo.exporters', 'nosuchtree-cameroon'))->assertNotFound();
});

it('does not match the programmatic exporter route without the -cameroon suffix', function () {
    $this->get('/exporters/sapele')->assertNotFound();
});

it('does not expose draft companies on the programmatic exporter page', function () {
    $species = Species::factory()->create(['slug' => 'iroko']);

    $visible = Company::factory()->publiclyVisible()->create(['legal_name' => 'Visible Iroko Co']);
    $visible->species()->attach($species);

    $draft = Company::factory()->create(['legal_name' => 'Draft Iroko Co']); // not publiclyVisible
    $draft->species()->attach($species);

    $this->get(route('pseo.exporters', 'iroko-cameroon'))
        ->assertOk()
        ->assertSee('Visible Iroko Co')
        ->assertDontSee('Draft Iroko Co');
});

// ---------------------------------------------------------------------------
// Slug Redirects (HandleSlugRedirects middleware)
// ---------------------------------------------------------------------------

it('issues a 301 redirect for a GET request to a registered old slug', function (): void {
    SlugRedirect::create([
        'from_slug' => 'old-company-name',
        'to_url' => '/exporters/iroko-cameroon',
    ]);

    $this->get('/old-company-name')
        ->assertStatus(301)
        ->assertRedirect('/exporters/iroko-cameroon');
});

it('does not redirect a GET request when no slug_redirects entry exists', function (): void {
    $this->get('/some-path-with-no-redirect')->assertStatus(404);
});

it('does not redirect POST requests even when a slug_redirects entry exists', function (): void {
    SlugRedirect::create([
        'from_slug' => 'old-contact',
        'to_url' => '/contact',
    ]);

    $response = $this->post('/old-contact');
    expect($response->status())->not->toBe(301);
});
