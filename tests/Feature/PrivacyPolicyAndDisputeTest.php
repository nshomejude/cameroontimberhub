<?php

use App\Models\ContactMessage;
use App\Models\Page;
use Database\Seeders\PageSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * Seeds a real page row from PageSeeder::PAGES, so these tests exercise the
 * actual seeded content rather than a hand-rolled fixture.
 */
function seedPage(string $slug): Page
{
    $definition = collect(PageSeeder::PAGES)->firstWhere('slug', $slug);

    expect($definition)->not->toBeNull();

    return Page::factory()->create($definition);
}

// ---------------------------------------------------------------------------
// Privacy Policy — §43 topics
// ---------------------------------------------------------------------------

it('renders the Privacy Policy page and addresses the required data-protection topics', function () {
    seedPage('privacy');

    $response = $this->get('/privacy');

    $response->assertOk();

    // Already covered before this change.
    $response->assertSee('access to a copy of the personal data', false);
    $response->assertSee('correction of inaccurate or incomplete personal data', false);
    $response->assertSee('Data retention');
    $response->assertSee('service providers who help us operate the Platform', false);
    $response->assertSee('International transfers');

    // Newly strengthened / added by this change.
    $response->assertSee('request deletion of your personal data', false);
    $response->assertSee('legally appropriate', false);
    $response->assertSee('do not currently offer an automated, self-service data export or account-deletion tool', false);
    $response->assertSee('How we manage service providers (processors)', false);
    $response->assertSee('Data incidents and breach response', false);
    $response->assertSee('Disputes and complaints');
});

it('does not claim a self-service export or delete feature that does not exist', function () {
    seedPage('privacy');

    $response = $this->get('/privacy');

    $response->assertSee('handled manually by our team', false);
});

// ---------------------------------------------------------------------------
// Dispute / complaint mechanism (Contact form "Dispute or complaint" category)
// ---------------------------------------------------------------------------

function validDisputePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Buyer',
        'company' => 'Nordic Timber Imports',
        'email' => 'jane@example.com',
        'phone' => '+44 20 7000 0000',
        'category' => 'dispute',
        'subject' => 'Supplier did not deliver as agreed',
        'message' => 'I placed an order with a supplier on the platform and the shipment never arrived as agreed.',
        'consent' => '1',
        'form_rendered_at' => now()->subSeconds(30)->timestamp,
    ], $overrides);
}

it('shows a "Dispute or complaint" category option on the contact form', function () {
    seedPage('contact');

    $this->get('/contact')->assertSee('Dispute or complaint');
});

it('persists a dispute/complaint submission with the category identifiable on the record', function () {
    Mail::fake();

    $this->post(route('contact.store'), validDisputePayload())
        ->assertRedirect()
        ->assertSessionHas('contact_sent', true);

    $message = ContactMessage::where('email', 'jane@example.com')->firstOrFail();

    expect($message->subject)->toContain('Dispute or complaint');
    expect($message->subject)->toContain('Supplier did not deliver as agreed');
    expect($message->message)->toContain('shipment never arrived');
});

it('rejects a dispute submission missing the required message field', function () {
    Mail::fake();

    $this->post(route('contact.store'), validDisputePayload(['message' => '']))
        ->assertSessionHasErrors('message');

    expect(ContactMessage::where('email', 'jane@example.com')->exists())->toBeFalse();
});

it('rejects a contact submission with an invalid category', function () {
    Mail::fake();

    $this->post(route('contact.store'), validDisputePayload(['category' => 'not-a-real-category']))
        ->assertSessionHasErrors('category');

    expect(ContactMessage::where('email', 'jane@example.com')->exists())->toBeFalse();
});

it('rejects a contact submission missing the category entirely', function () {
    Mail::fake();

    $payload = validDisputePayload();
    unset($payload['category']);

    $this->post(route('contact.store'), $payload)
        ->assertSessionHasErrors('category');
});
