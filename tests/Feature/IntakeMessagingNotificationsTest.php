<?php

use App\Enums\RfqStatus;
use App\Mail\BuyerRfqRejectedMail;
use App\Mail\BuyerRfqRoutedMail;
use App\Mail\ContactMessageMail;
use App\Mail\InquiryVerificationMail;
use App\Mail\RfqVerificationMail;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Lead;
use App\Models\NotificationPreference;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Notifications\LeadReceivedNotification;
use App\Notifications\MessageReceivedNotification;
use App\Notifications\QuoteAcceptedNotification;
use App\Notifications\QuoteDeclinedNotification;
use App\Notifications\RfqRoutedToExporter;
use App\Services\IntakeService;
use App\Services\LeadFlowService;
use App\Services\MessagingService;
use App\Services\QuoteService;
use App\Services\RfqRoutingResult;
use App\Services\RfqTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/* ------------------------------------------------------------------ helpers */

function imnRfq(array $attributes = []): Rfq
{
    return Rfq::create(array_merge([
        'reference_code' => 'RFQ-2026-'.Str::upper(Str::random(5)),
        'buyer_name' => 'Buyer',
        'buyer_email' => 'b@acme.test',
        'status' => 'new',
        'visibility' => 'public',
    ], $attributes));
}

function imnRfqPayload(array $overrides = []): array
{
    return array_merge([
        'buyer_name' => 'Jane Buyer',
        'buyer_email' => 'jane@acme.test',
        'buyer_country_code' => 'FR',
        'destination_country_code' => 'NL',
        'notes' => 'We need a steady supply of quality kiln-dried timber for joinery.',
        'consent' => '1',
        'species_text' => 'Sapele',
        'form' => 'logs',
        'quantity' => '25',
        'unit' => 'm3',
        'form_rendered_at' => now()->subSeconds(30)->timestamp,
    ], $overrides);
}

function imnCompanyWithUser(bool $leadsReceive = true): array
{
    $plan = Plan::factory()->create(['features' => ['leads_receive' => $leadsReceive]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return [$company, $user];
}

function imnAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function imnSubmittedQuote(Rfq $rfq, Company $company): Quote
{
    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'quantity' => 100,
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

/* --------------------------------------------- 1. mail failures do not 500 */

it('keeps the RFQ and reaches the thanks page when the verification mail fails', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    $this->post(route('rfq.store'), imnRfqPayload())->assertRedirect(route('rfq.thanks'));

    expect(Rfq::count())->toBe(1);
});

it('keeps the inquiry when its verification mail fails', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
    $company = Company::factory()->publiclyVisible()->create();

    $this->post(route('inquiry.store', $company->slug), [
        'name' => 'Bob Buyer',
        'email' => 'bob@acme.test',
        'message' => 'Hello, I am interested in your sawn timber for export to Europe.',
        'consent' => '1',
        'form_rendered_at' => now()->subSeconds(30)->timestamp,
    ])->assertRedirect()->assertSessionHas('inquiry_sent', true);

    expect(CompanyInquiry::count())->toBe(1);
});

it('delivers the contact form to the configured inbox', function () {
    Mail::fake();
    config(['contact.inbox' => 'inbox@cth.test']);

    $this->post(route('contact.store'), [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'category' => 'general',
        'subject' => 'Hello there',
        'message' => 'I am looking for a sapele supplier for my business.',
        'consent' => '1',
        'form_rendered_at' => now()->subSeconds(30)->timestamp,
    ])->assertSessionHas('contact_sent', true);

    Mail::assertSent(ContactMessageMail::class, fn ($m) => $m->hasTo('inbox@cth.test'));
});

/* ------------------------------------------------- 2. resend + expired link */

it('shows a friendly page (403) for an expired RFQ verify link', function () {
    $rfq = imnRfq();
    $url = app(IntakeService::class)->rfqVerifyUrl($rfq);

    $this->travel(49)->hours();

    $this->get($url)->assertForbidden()
        ->assertSee(__('messages.rfq_followup.link_invalid_title'))
        ->assertSee(route('rfq.resend'), false);

    expect($rfq->fresh()->email_verified_at)->toBeNull();
});

it('shows a friendly page for an invalid inquiry verify link', function () {
    Mail::fake();
    $company = Company::factory()->publiclyVisible()->create();
    $inquiry = $company->inquiries()->create(['name' => 'Bob', 'email' => 'bob@acme.test', 'message' => str_repeat('x', 30), 'status' => 'new']);

    $this->get(route('inquiry.verify', ['inquiry' => $inquiry->id, 'h' => sha1('bob@acme.test')]))
        ->assertForbidden()
        ->assertSee(__('messages.rfq_followup.link_invalid_title'));
});

it('re-sends the verification link for a matching unverified RFQ', function () {
    Mail::fake();
    $rfq = imnRfq(['buyer_email' => 'jane@acme.test']);

    $this->post(route('rfq.resend'), ['reference' => $rfq->reference_code, 'email' => 'JANE@acme.test'])
        ->assertRedirect(route('rfq.thanks'))
        ->assertSessionHas('rfq_resend_status', __('messages.rfq_followup.resend_generic'));

    Mail::assertSent(RfqVerificationMail::class, fn ($m) => $m->hasTo('jane@acme.test'));
});

it('answers identically and sends nothing for an unknown reference or wrong email', function () {
    Mail::fake();
    $rfq = imnRfq(['buyer_email' => 'jane@acme.test']);

    $this->post(route('rfq.resend'), ['reference' => $rfq->reference_code, 'email' => 'other@acme.test'])
        ->assertSessionHas('rfq_resend_status', __('messages.rfq_followup.resend_generic'));
    $this->post(route('rfq.resend'), ['reference' => 'RFQ-NOPE', 'email' => 'jane@acme.test'])
        ->assertSessionHas('rfq_resend_status', __('messages.rfq_followup.resend_generic'));

    Mail::assertNothingSent();
});

it('does not resend for an already-verified RFQ', function () {
    Mail::fake();
    $rfq = imnRfq(['buyer_email' => 'jane@acme.test', 'email_verified_at' => now()]);

    $this->post(route('rfq.resend'), ['reference' => $rfq->reference_code, 'email' => 'jane@acme.test']);

    Mail::assertNothingSent();
});

it('shows next steps, spam hint and the resend form on the thanks page', function () {
    $this->get(route('rfq.thanks'))->assertOk()
        ->assertSee(__('messages.rfq_followup.next_title'))
        ->assertSee(__('messages.rfq_followup.spam_hint'))
        ->assertSee(route('rfq.resend'), false);
});

/* ---------------------------------------------- 3. message mail coalescing */

function imnConversation(): array
{
    [$company, $supplier] = imnCompanyWithUser();
    $buyer = User::factory()->create();
    $conversation = Conversation::factory()->create([
        'user_id' => $buyer->getKey(),
        'company_id' => $company->getKey(),
    ]);
    ConversationParticipant::firstOrCreate(
        ['conversation_id' => $conversation->getKey(), 'user_id' => $supplier->getKey()],
        ['role' => ConversationParticipant::ROLE_SUPPLIER, 'company_id' => $company->getKey()],
    );

    return [$conversation, $buyer, $supplier];
}

it('emails a message recipient at most once per conversation per 30 minutes', function () {
    Notification::fake();
    Cache::flush();
    [$conversation, $buyer, $supplier] = imnConversation();
    $messaging = app(MessagingService::class);

    $messaging->post($conversation, $buyer, 'First message about delivery.');
    $messaging->post($conversation, $buyer, 'Second message about delivery.');

    Notification::assertSentToTimes($supplier, MessageReceivedNotification::class, 2);

    $withMail = 0;
    Notification::assertSentTo($supplier, MessageReceivedNotification::class, function ($n, array $channels) use (&$withMail) {
        $withMail += in_array('mail', $channels, true) ? 1 : 0;

        return true;
    });
    expect($withMail)->toBe(1);

    $this->travel(31)->minutes();
    Cache::forget(MessageReceivedNotification::coalesceKey($conversation->getKey(), $supplier->getKey()));
    $messaging->post($conversation, $buyer, 'Third message after a break.');
    $withMail = 0;
    Notification::assertSentTo($supplier, MessageReceivedNotification::class, function ($n, array $channels) use (&$withMail) {
        $withMail += in_array('mail', $channels, true) ? 1 : 0;

        return true;
    });
    expect($withMail)->toBe(2);
});

it('respects the email preference for message mails', function () {
    Notification::fake();
    Cache::flush();
    [$conversation, $buyer, $supplier] = imnConversation();
    NotificationPreference::forUser($supplier)->update(['channels' => ['push' => true, 'email' => false]]);

    app(MessagingService::class)->post($conversation, $buyer, 'Hello there supplier.');

    Notification::assertSentTo($supplier, MessageReceivedNotification::class, fn ($n, array $channels) => ! in_array('mail', $channels, true));
});

it('deep-links message mails to the right side of the conversation', function () {
    [$conversation, $buyer, $supplier] = imnConversation();
    $message = app(MessagingService::class)->postSystem($conversation, 'x');
    $n = new MessageReceivedNotification($message);

    expect($n->conversationUrl($buyer))->toBe(route('account.messages.show', $conversation))
        ->and($n->conversationUrl($supplier))->toContain('/dashboard')
        ->and($n->conversationUrl($supplier))->toContain('conversation='.$conversation->getKey());
});

/* ------------------------------------------------- 4. inquiry lead notifies */

it('notifies the supplier (even on a free plan) when an inquiry lead is created', function () {
    Notification::fake();
    [$company, $user] = imnCompanyWithUser(leadsReceive: false);
    $inquiry = $company->inquiries()->create(['name' => 'Bob', 'email' => 'bob@acme.test', 'message' => str_repeat('x', 30), 'status' => 'new']);

    app(IntakeService::class)->verifyInquiry($inquiry);
    app(LeadFlowService::class)->createFromInquiry($inquiry); // idempotent: no second notification

    Notification::assertSentToTimes($user, LeadReceivedNotification::class, 1);
    Notification::assertSentTo($user, LeadReceivedNotification::class, fn ($n, array $channels) => in_array('mail', $channels, true)
        && str_contains($n->leadUrl(), '/dashboard/leads/'));
});

/* ----------------------------------------- 5. routing reports skipped ones */

it('reports companies skipped for lack of leads_receive', function () {
    Notification::fake();
    Mail::fake();
    [$paid] = imnCompanyWithUser(true);
    [$free] = imnCompanyWithUser(false);
    $rfq = imnRfq(['status' => RfqStatus::Approved, 'email_verified_at' => now()]);

    $result = app(RfqTriageService::class)->routeDetailed($rfq, [$paid->id, $free->id], imnAdmin(), app(LeadFlowService::class));

    expect($result->routed)->toBe(1)
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]['company_id'])->toBe($free->id)
        ->and($result->skipped[0]['reason'])->toBe(RfqRoutingResult::REASON_NO_ENTITLEMENT)
        ->and($result->skippedSummary())->toContain($free->legal_name);
});

/* ------------------------------------------ 7. buyer emails on route/reject */

it('emails the buyer once when the RFQ is routed, and not when nothing was routed', function () {
    Notification::fake();
    Mail::fake();
    [$paid] = imnCompanyWithUser(true);
    [$free] = imnCompanyWithUser(false);
    $rfq = imnRfq(['status' => RfqStatus::Approved, 'email_verified_at' => now()]);
    $triage = app(RfqTriageService::class);

    $triage->route($rfq, [$free->id], imnAdmin(), app(LeadFlowService::class));
    Mail::assertNothingQueued();

    $triage->route($rfq, [$paid->id], imnAdmin(), app(LeadFlowService::class));
    Mail::assertQueued(BuyerRfqRoutedMail::class, fn ($m) => $m->hasTo('b@acme.test') && $m->count === 1);
    Mail::assertQueuedCount(1);
});

it('emails the buyer a polite rejection with the reason', function () {
    Mail::fake();
    $rfq = imnRfq(['email_verified_at' => now()]);

    app(RfqTriageService::class)->reject($rfq, 'Out of scope for our network.', imnAdmin());

    Mail::assertQueued(BuyerRfqRejectedMail::class, fn ($m) => $m->reason === 'Out of scope for our network.');
});

it('does not email a rejection for a spam RFQ', function () {
    Mail::fake();
    $rfq = imnRfq(['email_verified_at' => now(), 'status' => RfqStatus::Spam, 'is_spam' => true]);

    app(RfqTriageService::class)->reject($rfq, 'spam', imnAdmin());

    Mail::assertNothingQueued();
});

/* --------------------------------- 6. quote accept/decline notify supplier */

it('notifies the winner and auto-declined siblings exactly once on accept', function () {
    Notification::fake();
    Mail::fake();
    [$a, $userA] = imnCompanyWithUser();
    [$b, $userB] = imnCompanyWithUser();
    $rfq = Rfq::factory()->approved()->create();
    $winner = imnSubmittedQuote($rfq, $a);
    imnSubmittedQuote($rfq, $b);

    app(QuoteService::class)->accept($winner);

    Notification::assertSentToTimes($userA, QuoteAcceptedNotification::class, 1);
    Notification::assertSentToTimes($userB, QuoteDeclinedNotification::class, 1);
    Notification::assertNotSentTo($userA, QuoteDeclinedNotification::class);
});

it('notifies the supplier when a buyer declines', function () {
    Notification::fake();
    Mail::fake();
    [$a, $userA] = imnCompanyWithUser();
    $rfq = Rfq::factory()->approved()->create();
    $quote = imnSubmittedQuote($rfq, $a);

    app(QuoteService::class)->decline($quote, 'Too expensive');

    Notification::assertSentToTimes($userA, QuoteDeclinedNotification::class, 1);
});

/* ------------------------------------------------- 9. RFQ routed deep link */

it('deep-links the routed-RFQ email to the lead in the exporter panel', function () {
    Notification::fake();
    Mail::fake();
    [$company, $user] = imnCompanyWithUser();
    $rfq = imnRfq(['status' => RfqStatus::Approved, 'email_verified_at' => now()]);

    app(RfqTriageService::class)->route($rfq, [$company->id], imnAdmin(), app(LeadFlowService::class));

    $lead = Lead::where('rfq_id', $rfq->id)->firstOrFail();
    Notification::assertSentTo($user, RfqRoutedToExporter::class, fn ($n) => str_contains($n->leadUrl(), "/dashboard/leads/{$lead->id}"));
});

/* ------------------------------------------- real rendering (sync, no fake) */

it('renders every new email for real', function () {
    [$conversation, $buyer, $supplier] = imnConversation();
    $rfq = imnRfq();
    $message = app(MessagingService::class)->postSystem($conversation, 'Hello from the thread');
    $inquiry = $conversation->company->inquiries()->create(['name' => 'Bob', 'email' => 'bob@acme.test', 'message' => str_repeat('x', 30), 'status' => 'new']);
    $lead = Lead::create(['company_id' => $conversation->company_id, 'company_inquiry_id' => $inquiry->id, 'source' => 'inquiry', 'status' => 'new', 'buyer_name' => 'Bob', 'buyer_email' => 'bob@acme.test']);

    expect((new BuyerRfqRoutedMail($rfq, 3, 'https://example.test/r'))->render())->toContain($rfq->reference_code)
        ->and((new BuyerRfqRejectedMail($rfq, 'Out of scope'))->render())->toContain('Out of scope')
        ->and((string) (new MessageReceivedNotification($message))->toMail($supplier)->render())->toContain('conversation=')
        ->and((string) (new LeadReceivedNotification($lead))->toMail($supplier)->render())->toContain('/dashboard/leads/');
});

it('delivers message notifications end-to-end with the sync queue (mail transport faked)', function () {
    Mail::fake();
    Cache::flush();
    [$conversation, $buyer, $supplier] = imnConversation();

    app(MessagingService::class)->post($conversation, $buyer, 'Real delivery check.');

    expect($supplier->fresh()->notifications()->where('type', MessageReceivedNotification::class)->count())->toBe(1);
});
