<?php

use App\Enums\ConversationTopic;
use App\Enums\LeadStatus;
use App\Enums\MessageType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrganisationType;
use App\Enums\RfqType;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LotTransformation;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MessagingService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * Round-2 supplier/processor/artisan API gaps: order documents/payments/
 * cancel by reference, leads, company plan context + subscription,
 * capacities, lot transformations, artisan portfolio gallery fields.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    Storage::fake('documents');
});

function r2Supplier(array $companyAttributes = []): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create($companyAttributes);
    $company->users()->attach($user);

    return [$user, $company];
}

function r2AwardedOrder(Company $company, ?User $buyer = null): Order
{
    $rfq = Rfq::factory()->approved()->create($buyer ? ['user_id' => $buyer->getKey()] : []);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);
    $quote->items()->create([
        'description' => 'Sapele sawn timber', 'quantity' => 50, 'unit' => 'm3',
        'unit_price' => 185, 'line_total' => Quote::lineTotal(50, 185),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    $accepted = app(QuoteService::class)->accept($quote->fresh());

    return Order::where('quote_id', $accepted->getKey())->firstOrFail();
}

/* ------------------------------------------- order documents/payments/cancel */

it('attaches documents to an order with no conversation, and posts the chat card when one exists', function () {
    [$user, $company] = r2Supplier();
    $order = r2AwardedOrder($company);
    $base = "/api/v1/supplier/orders/{$order->reference_code}";

    $this->actingAs($user, 'sanctum')
        ->post("{$base}/documents", [
            'kind' => 'commercial_invoice',
            'documents' => [UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.reference', $order->reference_code);

    expect($order->documents()->count())->toBe(1);

    $buyer = User::factory()->create();
    $threaded = r2AwardedOrder($company, $buyer);
    $conversation = app(MessagingService::class)->start($buyer, $company, ConversationTopic::Order, order: $threaded);

    $this->actingAs($user, 'sanctum')
        ->post("/api/v1/supplier/orders/{$threaded->reference_code}/documents", [
            'documents' => [UploadedFile::fake()->create('bl.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect($threaded->documents()->count())->toBe(1)
        ->and($conversation->messages()->where('type', MessageType::OrderDocuments->value)->exists())->toBeTrue();

    [$stranger] = r2Supplier();
    $this->actingAs($stranger, 'sanctum')
        ->post("{$base}/documents", ['documents' => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('records an off-platform payment and refuses one above the total or on a cancelled order', function () {
    [$user, $company] = r2Supplier();
    $order = r2AwardedOrder($company);
    $base = "/api/v1/supplier/orders/{$order->reference_code}";

    $this->actingAs($user, 'sanctum')
        ->postJson("{$base}/payments", ['amount' => 1000, 'method' => 'Bank transfer'])
        ->assertOk()
        ->assertJsonPath('data.payment_status', OrderPaymentStatus::PartiallyPaid->value);

    expect((float) $order->fresh()->amount_paid)->toBe(1000.0)
        ->and($order->fresh()->payment_method)->toBe('Bank transfer');

    $this->actingAs($user, 'sanctum')
        ->postJson("{$base}/payments", ['amount' => 99999999])
        ->assertStatus(409)->assertJsonPath('error.code', 'order_action_not_allowed');

    $this->actingAs($user, 'sanctum')->postJson("{$base}/payments", [])->assertUnprocessable();

    $this->actingAs($user, 'sanctum')
        ->postJson("{$base}/cancel", ['reason' => 'Buyer withdrew'])
        ->assertOk()
        ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

    $this->actingAs($user, 'sanctum')
        ->postJson("{$base}/payments", ['amount' => 10])
        ->assertStatus(409)->assertJsonPath('error.code', 'order_action_not_allowed');

    $this->actingAs($user, 'sanctum')
        ->postJson("{$base}/cancel", ['reason' => 'again'])
        ->assertStatus(409)->assertJsonPath('error.code', 'order_transition_not_allowed');
});

it('records a payment through the conversation when the order has one', function () {
    [$user, $company] = r2Supplier();
    $buyer = User::factory()->create();
    $order = r2AwardedOrder($company, $buyer);
    $conversation = app(MessagingService::class)->start($buyer, $company, ConversationTopic::Order, order: $order);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/payments", ['amount' => (float) $order->total_amount])
        ->assertOk()
        ->assertJsonPath('data.payment_status', OrderPaymentStatus::Paid->value);

    expect($conversation->messages()->where('type', MessageType::PaymentConfirmed->value)->exists())->toBeTrue();
});

it('requires a reason to cancel', function () {
    [$user, $company] = r2Supplier();
    $order = r2AwardedOrder($company);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/cancel", [])
        ->assertUnprocessable();

    expect($order->fresh()->status)->toBe(OrderStatus::Awarded);
});

/* ------------------------------------------------------------------ leads */

it('lists, filters, shows and updates only the caller company leads', function () {
    [$user, $company] = r2Supplier();
    $other = Company::factory()->publiclyVisible()->create();

    $exportRfq = Rfq::factory()->approved()->create(['type' => RfqType::Export]);
    $mine = Lead::create(['company_id' => $company->getKey(), 'rfq_id' => $exportRfq->getKey(), 'source' => 'rfq', 'status' => 'new', 'buyer_name' => 'Ada']);
    Lead::create(['company_id' => $company->getKey(), 'source' => 'inquiry', 'status' => 'won', 'buyer_name' => 'Bob']);
    $foreign = Lead::create(['company_id' => $other->getKey(), 'source' => 'rfq', 'status' => 'new']);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/leads')
        ->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/leads?status=new')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->getKey());

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/leads?rfq_type=export')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.rfq.type', 'export');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/leads?status=bogus')->assertUnprocessable();

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/supplier/leads/{$mine->getKey()}")
        ->assertOk()->assertJsonPath('data.status.value', 'new');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/supplier/leads/{$mine->getKey()}", ['status' => 'contacted', 'notes' => 'Called Monday'])
        ->assertOk()
        ->assertJsonPath('data.status.value', LeadStatus::Contacted->value)
        ->assertJsonPath('data.notes', 'Called Monday');

    expect($mine->fresh()->last_activity_at)->not->toBeNull();

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/supplier/leads/{$foreign->getKey()}")->assertNotFound();
    $this->actingAs($user, 'sanctum')->patchJson("/api/v1/supplier/leads/{$foreign->getKey()}", ['status' => 'won'])->assertNotFound();
});

/* ------------------------------------------------------ company context */

it('exposes organisation_type and the effective plan on company, /auth/me and the dashboard', function () {
    [$user, $company] = r2Supplier(['type' => OrganisationType::Processor]);
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true, 'max_gallery' => 10]]);
    $subscription = Subscription::factory()->create(['company_id' => $company->getKey(), 'plan_id' => $plan->getKey()]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('data.organisation_type.value', 'processor')
        ->assertJsonPath('data.plan.slug', $plan->slug)
        ->assertJsonPath('data.plan.leads_receive', true)
        ->assertJsonPath('data.plan.max_gallery', 10)
        ->assertJsonPath('data.plan.expires_at', $subscription->ends_at->toIso8601String());

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.company.organisation_type.value', 'processor')
        ->assertJsonPath('data.company.plan.slug', $plan->slug);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.company.plan.slug', $plan->slug);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan.slug', $plan->slug)
        ->assertJsonPath('data.subscription.status', 'active')
        ->assertJsonPath('data.effective_plan.slug', $plan->slug);
});

it('returns a null plan and subscription when the company has none', function () {
    [$user] = r2Supplier();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription', null)
        ->assertJsonPath('data.plan', null);
});

/* ------------------------------------------------------------ capacities */

it('manages the caller company capacities with validation and scoping', function () {
    [$user, $company] = r2Supplier();
    $other = Company::factory()->create();
    $foreign = Capacity::create(['owner_type' => Company::class, 'owner_id' => $other->getKey(), 'capability' => 'Sawmilling', 'quantity' => 10, 'unit' => 'm3', 'period' => 'month']);

    $id = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/capacities', ['capability' => 'Kiln drying', 'quantity' => 500, 'unit' => 'm3', 'period' => 'month'])
        ->assertCreated()
        ->assertJsonPath('data.capability', 'Kiln drying')
        ->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/capacities', ['capability' => 'X', 'quantity' => 0, 'unit' => 'm3', 'period' => 'decade'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'period'], 'error.details');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/capacities')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($user, 'sanctum')->patchJson("/api/v1/supplier/capacities/{$id}", ['quantity' => 750])
        ->assertOk()->assertJsonPath('data.quantity', '750.00');

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/supplier/capacities/{$foreign->getKey()}")->assertNotFound();
    $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/supplier/capacities/{$foreign->getKey()}")->assertNotFound();

    $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/supplier/capacities/{$id}")->assertNoContent();

    expect(Capacity::whereKey($id)->exists())->toBeFalse()
        ->and($company->getKey())->toBeInt();
});

/* --------------------------------------------------- lot transformations */

it('lists and shows only lot transformations where the caller company is the processor', function () {
    [$user, $company] = r2Supplier(['type' => OrganisationType::Processor]);
    $other = Company::factory()->create();

    $mine = LotTransformation::recordFor($company->getKey(), 'sawing', [], [], notes: 'Batch A');
    $foreign = LotTransformation::recordFor($other->getKey(), 'sawing', [], []);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/lot-transformations')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->getKey());

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/supplier/lot-transformations/{$mine->getKey()}")
        ->assertOk()->assertJsonPath('data.notes', 'Batch A')->assertJsonPath('data.input_lots', []);

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/supplier/lot-transformations/{$foreign->getKey()}")->assertNotFound();
});

/* ------------------------------------------------------ artisan portfolio */

it('saves and returns portfolio fields on gallery items', function () {
    [$user, $company] = r2Supplier(['type' => OrganisationType::Artisan]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/company', [
        'gallery' => [[
            'image_path' => 'companies/gallery/chair.jpg',
            'caption' => 'Iroko chair',
            'description' => 'Hand-carved dining chair',
            'is_portfolio' => true,
            'materials_used' => 'Iroko, linseed oil',
            'completed_on' => '2026-05-01',
        ]],
    ])
        ->assertOk()
        ->assertJsonPath('data.gallery.0.is_portfolio', true)
        ->assertJsonPath('data.gallery.0.materials_used', 'Iroko, linseed oil')
        ->assertJsonPath('data.gallery.0.completed_on', '2026-05-01')
        ->assertJsonPath('data.gallery.0.description', 'Hand-carved dining chair');

    expect($company->gallery()->portfolio()->count())->toBe(1);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/company', [
        'gallery' => [['image_path' => 'companies/gallery/x.jpg', 'completed_on' => now()->addYear()->toDateString()]],
    ])->assertUnprocessable();
});

/* ------------------------------------------------------- web + currency */

it('shows the Find an Artisan chip on the transformation network page', function () {
    $this->get(route('transformation-network'))
        ->assertOk()
        ->assertSee(__('messages.transformation.find_artisan'));
});

it('offers the portfolio fields on the exporter company gallery repeater', function () {
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('exporter'));
    [$user, $company] = r2Supplier(['type' => OrganisationType::Artisan]);
    $company->gallery()->create(['image_path' => 'companies/gallery/a.jpg', 'is_portfolio' => true, 'materials_used' => 'Ebony']);

    $this->actingAs($user);

    \Livewire\Livewire::test(\App\Filament\Exporter\Resources\Companies\Pages\EditCompany::class, ['record' => $company->getRouteKey()])
        ->assertOk()
        ->assertSee(__('messages.company.portfolio_materials_used'))
        ->assertSee(__('messages.company.portfolio_is_portfolio'));
});
