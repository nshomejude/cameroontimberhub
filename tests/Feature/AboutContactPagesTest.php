<?php

use App\Enums\ConsentPurpose;
use App\Mail\ContactMessageMail;
use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\ContactMessage;
use App\Models\Page;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Support\Facades\Mail;

/**
 * @return array<string, mixed>
 */
function aboutPageData(array $overrides = []): array
{
    return array_replace_recursive([
        'eyebrow' => 'About Cameroon Timber Hub',
        'intro' => 'The marketplace intro paragraph.',
        'pillars' => [
            ['icon' => 'shield-check', 'title' => 'Trusted & Verified', 'text' => 'Documents are reviewed.'],
            ['icon' => 'globe-alt', 'title' => 'Global Reach', 'text' => 'Buyers worldwide.'],
        ],
        'mission' => 'Our mission statement text.',
        'vision' => 'Our vision statement text.',
        'why_title' => 'Why Cameroon?',
        'why_intro' => 'Cameroon has rich forest resources.',
        'why_points' => ['Diverse hardwood species of global value'],
        'story_title' => 'Why we built Cameroon Timber Hub',
        'cta_title' => 'Be part of the story',
        'cta_text' => 'Join the network.',
        'blocks' => [
            ['type' => 'paragraph', 'content' => 'CMS managed body copy.'],
        ],
    ], $overrides);
}

function makeAboutPage(array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'slug' => 'about',
        'title' => 'About Cameroon Timber Hub',
        'h1' => 'Building Africa’s timber trade gateway to the world',
        'template' => 'about',
        'meta_description' => 'About the verified Cameroon timber marketplace.',
        'is_published' => true,
        'data' => aboutPageData(),
    ], $attributes));
}

function makeContactPage(array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'slug' => 'contact',
        'title' => 'Contact Cameroon Timber Hub',
        'h1' => 'We’re here to connect, support & grow together',
        'template' => 'static',
        'meta_description' => 'Contact the Cameroon Timber Hub team.',
        'is_published' => true,
        'data' => [
            'eyebrow' => 'Contact us',
            'intro' => 'Have a question or a partnership inquiry?',
            'details_blurb' => 'We are here to answer your questions.',
            'pillars' => [
                ['icon' => 'lifebuoy', 'title' => 'Dedicated Support', 'text' => 'Always ready to help.'],
            ],
        ],
    ], $attributes));
}

/** Pulls every application/ld+json payload out of a rendered page. */
function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(function (string $raw) {
        $decoded = json_decode($raw, true);
        expect(json_last_error())->toBe(JSON_ERROR_NONE);

        return $decoded;
    }, $m[1]);
}

function validContactPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Buyer',
        'company' => 'Nordic Timber Imports',
        'email' => 'jane@example.com',
        'phone' => '+44 20 7000 0000',
        'subject' => 'Inquiry about sapele',
        'message' => 'I am looking for a reliable sapele supplier in Cameroon for a recurring order.',
        'consent' => '1',
        'form_rendered_at' => now()->subSeconds(30)->timestamp,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// About
// ---------------------------------------------------------------------------

it('renders the about page with its key sections', function () {
    makeAboutPage();

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Building Africa’s timber trade gateway to the world', false)
        ->assertSee('Trusted &amp; Verified', false)   // pillar strip
        ->assertSee('Our Mission')
        ->assertSee('Our Vision')
        ->assertSee('Why Cameroon?')
        ->assertSee('Diverse hardwood species of global value')
        ->assertSee('Be part of the story');
});

it('still surfaces the CMS-managed about body, and re-renders it when an admin edits the page', function () {
    $page = makeAboutPage();

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('CMS managed body copy.');

    $page->update([
        'h1' => 'A completely new headline',
        'data' => aboutPageData([
            'mission' => 'A completely new mission.',
            'blocks' => [['type' => 'paragraph', 'content' => 'Freshly edited body copy.']],
        ]),
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('A completely new headline')
        ->assertSee('A completely new mission.')
        ->assertSee('Freshly edited body copy.')
        ->assertDontSee('CMS managed body copy.');
});

it('renders about counters from real aggregates, not hardcoded mockup numbers', function () {
    makeAboutPage();

    // Nothing published yet: every counter query returns zero, so the whole
    // panel is omitted rather than padded with invented figures.
    $this->get(route('about'))
        ->assertOk()
        ->assertDontSee('Verified suppliers')
        ->assertDontSee('Export markets reached');

    $companyA = Company::factory()->publiclyVisible()->create();
    $companyB = Company::factory()->publiclyVisible()->create();
    Species::factory()->count(3)->create(['is_published' => true]);
    Product::factory()->active()->create(['company_id' => $companyA->id]);
    CompanyExportMarket::create(['company_id' => $companyA->id, 'country_code' => 'DE']);
    CompanyExportMarket::create(['company_id' => $companyB->id, 'country_code' => 'CN']);

    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('Verified suppliers')
        ->toContain('Timber species catalogued')
        ->toContain('Export markets reached')
        ->toContain('Products listed');

    // The rendered figures track the fixtures above.
    $value = fn (string $label) => (function () use ($html, $label) {
        preg_match('#>([\d,]+)</dt>\s*<dd[^>]*>'.preg_quote($label, '#').'#s', $html, $m);

        return $m[1] ?? null;
    })();

    expect($value('Verified suppliers'))->toBe('2')
        ->and($value('Timber species catalogued'))->toBe((string) Species::published()->count())
        ->and($value('Export markets reached'))->toBe('2')
        ->and($value('Products listed'))->toBe('1');

    // Add a third supplier — the counter must move with the data.
    Company::factory()->publiclyVisible()->create();

    $html = $this->get(route('about'))->assertOk()->getContent();
    preg_match('#>([\d,]+)</dt>\s*<dd[^>]*>Verified suppliers#s', $html, $m);
    expect($m[1])->toBe('3');
});

it('emits valid AboutPage and BreadcrumbList JSON-LD on the about page', function () {
    makeAboutPage();

    $blocks = jsonLdBlocks($this->get(route('about'))->assertOk()->getContent());

    $types = collect($blocks)->pluck('@type')->filter()->all();

    expect($types)->toContain('AboutPage')
        ->and($types)->toContain('BreadcrumbList');

    $about = collect($blocks)->firstWhere('@type', 'AboutPage');
    expect($about['url'])->toBe(route('about'))
        ->and($about['mainEntity']['@type'])->toBe('Organization')
        ->and($about['mainEntity']['logo'])->toBe(url('/brand/logo-600.png'));

    // No social profiles configured by default → no invented sameAs claims.
    expect($about['mainEntity'])->not->toHaveKey('sameAs');

    $crumbs = collect($blocks)->firstWhere('@type', 'BreadcrumbList');
    expect($crumbs['itemListElement'])->toHaveCount(2)
        ->and($crumbs['itemListElement'][0]['name'])->toBe('Home');
});

it('keeps rendering a legacy static about page through the generic template', function () {
    // Pages created before the dedicated layout still resolve — /about must not
    // break for an operator whose row still says template = static.
    Page::factory()->create([
        'slug' => 'about',
        'title' => 'About Us',
        'h1' => 'Who we are',
        'template' => 'static',
        'is_published' => true,
        'data' => ['blocks' => [['type' => 'paragraph', 'content' => 'Legacy about copy.']]],
    ]);

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Who we are')
        ->assertSee('Legacy about copy.');
});

// ---------------------------------------------------------------------------
// Contact — page
// ---------------------------------------------------------------------------

it('renders the contact page with its key sections and real contact details', function () {
    makeContactPage();

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('We’re here to connect, support &amp; grow together', false)
        ->assertSee('Get in Touch')
        ->assertSee('Send Us a Message')
        ->assertSee('Find Us')
        ->assertSee('Office Hours')
        ->assertSee('Dedicated Support')
        ->assertSee(config('contact.address.lines')[0])
        ->assertSee(config('contact.emails')[0])
        ->assertSee(config('contact.phones')[0]);
});

it('drives contact details from configuration, not from hardcoded markup', function () {
    makeContactPage();

    config([
        'contact.emails' => ['hello@example.test'],
        'contact.phones' => ['+237 6 00 00 00 00'],
        'contact.address.lines' => ['1 Relocated Street'],
    ]);

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('hello@example.test')
        ->assertSee('+237 6 00 00 00 00')
        ->assertSee('1 Relocated Street')
        ->assertDontSee('info@cameroontimberhub.africa');
});

it('lets the CMS page override configured contact details', function () {
    makeContactPage([
        'data' => [
            'intro' => 'Overridden intro',
            'contact' => ['emails' => ['cms-managed@example.test']],
        ],
    ]);

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee('cms-managed@example.test');
});

it('links out to a map instead of embedding a third-party map script', function () {
    makeContactPage();

    $html = $this->get(route('contact'))->assertOk()->getContent();

    expect($html)->toContain('openstreetmap.org')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('maps.googleapis.com')
        ->and($html)->not->toContain('google.com/maps/embed');
});

it('emits valid ContactPage and BreadcrumbList JSON-LD on the contact page', function () {
    makeContactPage();

    $blocks = jsonLdBlocks($this->get(route('contact'))->assertOk()->getContent());
    $types = collect($blocks)->pluck('@type')->filter()->all();

    expect($types)->toContain('ContactPage')
        ->and($types)->toContain('BreadcrumbList');

    $contact = collect($blocks)->firstWhere('@type', 'ContactPage');

    expect($contact['url'])->toBe(route('contact'))
        ->and($contact['mainEntity']['contactPoint'][0]['telephone'])->toBe(config('contact.phones')[0])
        ->and($contact['mainEntity']['address']['addressLocality'])->toBe(config('contact.address.locality'))
        ->and($contact['mainEntity'])->not->toHaveKey('sameAs'); // none configured
});

it('omits the contact point from JSON-LD when no phone or mailbox is configured', function () {
    makeContactPage();
    config(['contact.phones' => [], 'contact.emails' => []]);

    $blocks = jsonLdBlocks($this->get(route('contact'))->assertOk()->getContent());
    $contact = collect($blocks)->firstWhere('@type', 'ContactPage');

    expect($contact['mainEntity'])->not->toHaveKey('contactPoint');
});

// ---------------------------------------------------------------------------
// Contact — form
// ---------------------------------------------------------------------------

it('accepts a valid contact submission and mails the team', function () {
    Mail::fake();
    makeContactPage();

    $this->post(route('contact.store'), validContactPayload())
        ->assertRedirect()
        ->assertSessionHas('contact_sent', true)
        ->assertSessionHasNoErrors();

    Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail) {
        return $mail->hasTo(config('mail.from.address'))
            && $mail->data['email'] === 'jane@example.com'
            && $mail->data['company'] === 'Nordic Timber Imports'
            && str_contains($mail->data['message'], 'sapele supplier');
    });
});

it('persists a contact message and a Consent record when the checkbox is checked', function () {
    Mail::fake();
    makeContactPage();

    $this->post(route('contact.store'), validContactPayload())->assertRedirect();

    $message = ContactMessage::where('email', 'jane@example.com')->firstOrFail();

    expect($message->name)->toBe('Jane Buyer')
        ->and($message->company)->toBe('Nordic Timber Imports')
        ->and($message->subject)->toBe('Inquiry about sapele')
        ->and($message->consents()->where('purpose', ConsentPurpose::ContactMessageSharing->value)->exists())->toBeTrue();
});

// `consent` is validated as `accepted` on ContactController::store(), so an
// unchecked box never reaches persistence — the submission is rejected by
// validation (see "rejects an invalid contact submission..." below) before
// createContactMessage() is ever called.

it('rejects an invalid contact submission and repopulates the submitted input', function () {
    Mail::fake();
    makeContactPage();

    $response = $this->from(route('contact'))->post(route('contact.store'), validContactPayload([
        'email' => 'not-an-email',
        'message' => 'too short',
        'consent' => '0',
    ]));

    $response->assertRedirect(route('contact'))
        ->assertSessionHasErrors(['email', 'message', 'consent']);

    Mail::assertNothingSent();

    // Old input survives the redirect and is rendered back into the form.
    $this->followingRedirects()
        ->post(route('contact.store'), validContactPayload([
            'email' => 'not-an-email',
            'message' => 'too short',
            'consent' => '0',
        ]))
        ->assertOk()
        ->assertSee('value="Jane Buyer"', false)
        ->assertSee('not-an-email', false)
        ->assertSee('aria-describedby="contact-email-error"', false);
});

it('silently accepts a honeypot submission without mailing anyone', function () {
    Mail::fake();
    makeContactPage();

    $this->post(route('contact.store'), validContactPayload([
        'website' => 'http://spam.example.com',
    ]))->assertRedirect()->assertSessionHas('contact_sent', true);

    Mail::assertNothingSent();
});

it('silently accepts a submission that was filled in faster than a human could', function () {
    Mail::fake();
    makeContactPage();

    $this->post(route('contact.store'), validContactPayload([
        'form_rendered_at' => now()->timestamp,
    ]))->assertRedirect()->assertSessionHas('contact_sent', true);

    Mail::assertNothingSent();
});

it('throttles repeated contact submissions', function () {
    Mail::fake();
    makeContactPage();

    foreach (range(1, 6) as $i) {
        $this->post(route('contact.store'), validContactPayload([
            'subject' => "Inquiry number {$i}",
        ]))->assertRedirect();
    }

    $this->post(route('contact.store'), validContactPayload(['subject' => 'One too many']))
        ->assertStatus(429);
})->group('throttle');
