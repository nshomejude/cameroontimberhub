<?php

use App\Enums\CompanyStatus;
use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Favorite;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Notifications\RfqWithdrawnNotification;
use App\Services\DisputeService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */

function bacBuyer(array $attributes = []): User
{
    return User::factory()->create($attributes + ['email' => 'bac'.uniqid().'@example.com']);
}

/** An approved RFQ owned by $buyer, routed to $company (whose one member is returned). */
function bacRoutedRfq(User $buyer, ?Company $company = null): array
{
    $company ??= Company::factory()->publiclyVisible()->create();
    $member = User::factory()->create();
    $company->users()->attach($member->getKey(), ['role' => 'owner', 'is_primary' => true]);

    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey(), 'buyer_email' => $buyer->email]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);

    RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);

    return [$rfq, $company, $member];
}

/** A real order (quote accepted) for $buyer. */
function bacOrder(User $buyer): Order
{
    [$rfq, $company] = bacRoutedRfq($buyer);
    $routing = RfqCompany::where('rfq_id', $rfq->getKey())->firstOrFail();

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'rfq_company_id' => $routing->getKey(),
    ]);
    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(), 'description' => 'Sawn timber', 'quantity' => 100,
        'unit_price' => 185.00, 'line_total' => Quote::lineTotal(100, 185.00),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote, $buyer);

    return Order::where('quote_id', $quote->getKey())->firstOrFail();
}

/* ----------------------------------------------------------------- settings */

it('renders the settings page with profile, password, 2FA and preferences', function () {
    $buyer = bacBuyer();

    $this->actingAs($buyer)->get('/account/settings')
        ->assertOk()
        ->assertSee(__('messages.account_center.profile_title'))
        ->assertSee(__('messages.account_center.password_title'))
        ->assertSee(route('two-factor.show'), false)
        ->assertSee(__('messages.account_center.prefs_title'))
        ->assertSee(route('account.settings'), false);
});

it('updates the profile with the API validation rules', function () {
    $buyer = bacBuyer();

    $this->actingAs($buyer)->put('/account/settings/profile', ['name' => 'Ada Buyer', 'phone' => '+237600000000', 'locale' => 'fr'])
        ->assertRedirect(route('account.settings'));

    expect($buyer->fresh())->name->toBe('Ada Buyer')->phone->toBe('+237600000000')->locale->toBe('fr');

    $this->actingAs($buyer)->put('/account/settings/profile', ['name' => 'X', 'locale' => 'klingon'])
        ->assertSessionHasErrorsIn('profile', ['locale']);
});

it('changes the password only with the current one and revokes API tokens', function () {
    $buyer = bacBuyer(['password' => 'old-Password-123']);
    $buyer->createToken('phone');

    $this->actingAs($buyer)->put('/account/settings/password', [
        'current_password' => 'wrong', 'password' => 'new-Password-456!', 'password_confirmation' => 'new-Password-456!',
    ])->assertSessionHasErrorsIn('password', ['current_password']);

    expect($buyer->tokens()->count())->toBe(1);

    $this->actingAs($buyer)->put('/account/settings/password', [
        'current_password' => 'old-Password-123', 'password' => 'new-Password-456!', 'password_confirmation' => 'new-Password-456!',
    ])->assertRedirect(route('account.settings'))->assertSessionHasNoErrors();

    expect(Hash::check('new-Password-456!', $buyer->fresh()->password))->toBeTrue()
        ->and($buyer->tokens()->count())->toBe(0);
});

it('saves notification preferences, unchecked boxes turning a key off', function () {
    $buyer = bacBuyer();

    $this->actingAs($buyer)->put('/account/settings/notifications', [
        'channels' => ['push' => '0', 'email' => '1'],
        'types' => ['quote_received' => '1', 'order_status_changed' => '0', 'message_received' => '1', 'dispute_reply' => '1'],
    ])->assertRedirect(route('account.settings'));

    $pref = NotificationPreference::forUser($buyer);
    expect($pref->channels['push'])->toBeFalse()
        ->and($pref->channels['email'])->toBeTrue()
        ->and($pref->types['order_status_changed'])->toBeFalse();
});

/* ------------------------------------------------------------ notifications */

function bacNotify(User $user, ?string $readAt = null): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\QuoteReceivedNotification',
        'data' => ['type' => 'quote_received', 'title' => 'New quote received', 'body' => 'Body text', 'reference' => 'RFQ-1'],
        'read_at' => $readAt,
    ]);
}

it('lists notifications with an unread badge and marks one / all read', function () {
    $buyer = bacBuyer();
    $first = bacNotify($buyer);
    bacNotify($buyer);
    $other = bacNotify(bacBuyer());

    $this->actingAs($buyer)->get('/account/notifications')
        ->assertOk()
        ->assertSee('New quote received')
        ->assertSee(__('messages.account_center.notifications_unread', ['count' => 2]));

    // A stranger's notification never resolves.
    $this->actingAs($buyer)->post("/account/notifications/{$other->id}/read")->assertNotFound();

    $this->actingAs($buyer)->post("/account/notifications/{$first->id}/read")->assertRedirect();
    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($buyer->unreadNotifications()->count())->toBe(1);

    $this->actingAs($buyer)->post('/account/notifications/read-all')->assertRedirect();
    expect($buyer->unreadNotifications()->count())->toBe(0)
        ->and($other->fresh()->read_at)->toBeNull();
});

it('shows the notifications and new nav entries in the account layout', function () {
    $buyer = bacBuyer();
    bacNotify($buyer);

    $this->actingAs($buyer)->get('/account/requests')
        ->assertOk()
        ->assertSee(route('account.notifications'), false)
        ->assertSee(route('account.saved'), false)
        ->assertSee(route('account.disputes'), false)
        ->assertSee(route('account.settings'), false);
});

/* -------------------------------------------------------------------- saved */

it('saves, lists and removes a supplier from the company page', function () {
    $buyer = bacBuyer();
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs($buyer)->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee(route('account.saved.store', $company->slug), false);

    $this->actingAs($buyer)->post(route('account.saved.store', $company->slug))->assertRedirect();
    expect(Favorite::where('user_id', $buyer->id)->where('favoritable_type', Company::class)->count())->toBe(1);

    // Idempotent, and now the page offers "unsave".
    $this->actingAs($buyer)->post(route('account.saved.store', $company->slug));
    expect(Favorite::where('user_id', $buyer->id)->count())->toBe(1);

    $this->actingAs($buyer)->get(route('companies.show', $company->slug))
        ->assertSee(route('account.saved.destroy', $company->slug), false);

    $this->actingAs($buyer)->get('/account/saved')->assertOk()->assertSee($company->name);

    $this->actingAs($buyer)->delete(route('account.saved.destroy', $company->slug))->assertRedirect();
    expect(Favorite::where('user_id', $buyer->id)->count())->toBe(0);
});

it('does not offer the save toggle to guests and refuses hidden companies', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertDontSee(route('account.saved.store', $company->slug), false);

    $hidden = Company::factory()->create(['status' => CompanyStatus::Suspended]);
    $this->actingAs(bacBuyer())->post(route('account.saved.store', $hidden->slug))->assertNotFound();
});

/* ----------------------------------------------------------------- disputes */

it('lists the buyer disputes across orders on web and API, and nobody elses', function () {
    $buyer = bacBuyer();
    $order = bacOrder($buyer);
    app(DisputeService::class)->open($order, $buyer, DisputeCategory::Quality, 'Grade mismatch.');

    $stranger = bacBuyer();
    $strangerOrder = bacOrder($stranger);
    app(DisputeService::class)->open($strangerOrder, $stranger, DisputeCategory::Quality, 'Not mine.');

    $this->actingAs($buyer)->get('/account/disputes')
        ->assertOk()
        ->assertSee('Grade mismatch.')
        ->assertDontSee('Not mine.')
        ->assertSee(route('disputes.show', ['order' => $order->getKey(), 'dispute' => $order->disputes()->first()->getKey()]), false);

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/disputes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.order_reference', $order->reference_code)
        ->assertJsonPath('meta.total', 1);
});

it('accepts multipart dispute evidence over the API with the web validation', function () {
    Storage::fake(config('filesystems.default'));
    Storage::fake('local');
    $buyer = bacBuyer();
    $order = bacOrder($buyer);
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Quality, 'Grade mismatch.');

    $url = "/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/evidence";

    $this->actingAs($buyer, 'sanctum')->postJson($url, [])->assertUnprocessable();

    $this->actingAs($buyer, 'sanctum')->post($url, [
        'description' => 'Photo of the stack',
        'file' => UploadedFile::fake()->create('stack.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.status', DisputeStatus::CounterpartyResponsePending->value);

    expect($dispute->evidence()->count())->toBe(1);

    // Another buyer cannot reach it.
    $this->actingAs(bacBuyer(), 'sanctum')->postJson($url, ['description' => 'x'])->assertNotFound();
});

it('appeals a resolved dispute over the API and refuses an unresolved one', function () {
    $buyer = bacBuyer();
    $order = bacOrder($buyer);
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Quality, 'Grade mismatch.');
    $url = "/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/appeal";

    $this->actingAs($buyer, 'sanctum')->postJson($url)
        ->assertStatus(409)->assertJsonPath('error.code', 'dispute_not_actionable');

    $dispute->update(['status' => DisputeStatus::Resolved, 'resolved_at' => now()]);

    $this->actingAs($buyer, 'sanctum')->postJson($url)
        ->assertOk()->assertJsonPath('data.status', DisputeStatus::Appealed->value);
});

/* --------------------------------------------------------------- rfq cancel */

it('lets the buyer withdraw an open RFQ on the web and notifies routed suppliers', function () {
    Notification::fake();
    $buyer = bacBuyer();
    [$rfq, , $member] = bacRoutedRfq($buyer);

    $this->actingAs($buyer)->get('/account/requests')
        ->assertOk()->assertSee(route('account.rfqs.cancel', $rfq->reference_code), false);

    $this->actingAs($buyer)->post(route('account.rfqs.cancel', $rfq->reference_code))
        ->assertRedirect()->assertSessionHas('status');

    expect($rfq->fresh()->status)->toBe(RfqStatus::Closed);
    Notification::assertSentTo($member, RfqWithdrawnNotification::class, function ($n, array $channels) {
        return in_array('mail', $channels, true) && in_array('database', $channels, true);
    });

    // Already closed: refused, and no cancel button anymore.
    $this->actingAs($buyer)->post(route('account.rfqs.cancel', $rfq->reference_code))->assertSessionHas('error');
    $this->actingAs($buyer)->get('/account/requests')
        ->assertDontSee(route('account.rfqs.cancel', $rfq->reference_code), false);
});

it('cancels an RFQ over the API, 409s once awarded and 404s for another buyer', function () {
    Notification::fake();
    $buyer = bacBuyer();
    [$rfq] = bacRoutedRfq($buyer);

    $this->actingAs(bacBuyer(), 'sanctum')->postJson("/api/v1/rfqs/{$rfq->reference_code}/cancel")->assertNotFound();

    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/rfqs/{$rfq->reference_code}/cancel")
        ->assertOk()->assertJsonPath('data.status', RfqStatus::Closed->value);

    $order = bacOrder($buyer);
    $awarded = Rfq::findOrFail($order->rfq_id);
    $awarded->update(['status' => RfqStatus::Approved]); // even if re-opened, an order blocks it

    $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/rfqs/{$awarded->reference_code}/cancel")
        ->assertStatus(409)->assertJsonPath('error.code', 'rfq_not_cancellable');
});

/* ------------------------------------------------- conversations visibility */

it('refuses to start a conversation with a suspended company but allows pending ones', function () {
    $buyer = bacBuyer(['email_verified_at' => now()]);
    $hidden = Company::factory()->create(['status' => CompanyStatus::Suspended]);

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/conversations', ['company' => $hidden->slug, 'body' => 'Hello'])
        ->assertStatus(422)->assertJsonPath('error.code', 'company_unavailable');

    $this->actingAs($buyer)->from('/account/messages/new')
        ->post('/account/messages/start', ['company' => $hidden->slug])
        ->assertRedirect('/account/messages/new')
        ->assertSessionHasErrors('company');

    expect(Conversation::count())->toBe(0);

    $pending = Company::factory()->create(['status' => CompanyStatus::Pending]);
    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/conversations', ['company' => $pending->slug, 'body' => 'Hello'])
        ->assertCreated();

    $visible = Company::factory()->publiclyVisible()->create();
    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/conversations', ['company' => $visible->slug, 'body' => 'Hello'])
        ->assertCreated();
});
