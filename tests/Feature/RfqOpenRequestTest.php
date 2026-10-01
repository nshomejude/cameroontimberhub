<?php

use App\Enums\OrganisationType;
use App\Enums\RfqStatus;
use App\Enums\RfqType;
use App\Filament\Exporter\Pages\BuyerRequests;
use App\Mail\BuyerRfqRoutedMail;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\User;
use App\Notifications\RfqRoutedToExporter;
use App\Services\IntakeService;
use App\Services\LeadFlowService;
use App\Services\RfqTriageService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
    Mail::fake();
    Notification::fake();
});

/** A verified, free-plan (via CompanyObserver) supplier handling $species, with one user. */
function openReqSupplier(Species $species, array $attributes = []): array
{
    $company = Company::factory()->verified()->create($attributes);
    $company->species()->attach($species);
    $user = User::factory()->create();
    $company->users()->attach($user);

    return [$user, $company->fresh()];
}

function openReqRfq(Species $species, array $attributes = []): Rfq
{
    $rfq = Rfq::factory()->verified()->create(array_merge(['status' => RfqStatus::New, 'type' => RfqType::Export], $attributes));
    $rfq->items()->create(['species_id' => $species->id, 'species_text' => $species->common_name, 'form' => 'sawn', 'quantity' => 20, 'unit' => 'm3']);

    return $rfq->fresh();
}

function openReqAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('seeds the free plan with leads_receive on, so free-plan companies receive routed RFQs', function () {
    $species = Species::factory()->create();
    [, $company] = openReqSupplier($species);

    expect($company->plan->slug)->toBe('free')
        ->and($company->hasFeature('leads_receive'))->toBeTrue();

    $rfq = openReqRfq($species, ['status' => RfqStatus::Approved]);
    $routed = app(RfqTriageService::class)->route($rfq, [$company->id], openReqAdmin(), app(LeadFlowService::class));

    expect($routed)->toBe(1);
});

it('stops routing to free-plan companies once an admin turns leads_receive off on the plan', function () {
    $species = Species::factory()->create();
    [, $company] = openReqSupplier($species);

    $free = Plan::where('slug', 'free')->firstOrFail();
    $free->update(['features' => array_merge($free->features, ['leads_receive' => false])]);

    $rfq = openReqRfq($species);
    app(RfqTriageService::class)->approve($rfq, openReqAdmin());

    expect(RfqCompany::where('rfq_id', $rfq->id)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('auto-routes on approval to matching suppliers only, notifying them and the buyer', function () {
    $sapele = Species::factory()->create();
    $iroko = Species::factory()->create();
    [$matchUser, $match] = openReqSupplier($sapele);
    [$otherUser, $other] = openReqSupplier($iroko);
    $unverified = Company::factory()->create();
    $unverified->species()->attach($sapele);

    $rfq = openReqRfq($sapele);
    app(RfqTriageService::class)->approve($rfq, openReqAdmin());

    expect(RfqCompany::where('rfq_id', $rfq->id)->pluck('company_id')->all())->toBe([$match->id]);
    Notification::assertSentTo($matchUser, RfqRoutedToExporter::class);
    Notification::assertNotSentTo($otherUser, RfqRoutedToExporter::class);
    Mail::assertQueued(BuyerRfqRoutedMail::class);

    // Manual routing still works for additions and does not double-route.
    $result = app(RfqTriageService::class)->routeDetailed($rfq->fresh(), [$match->id, $other->id], openReqAdmin(), app(LeadFlowService::class));
    expect($result->routed)->toBe(1);
});

it('does not auto-route when the toggle is off', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    openReqSupplier($species);

    $rfq = openReqRfq($species);
    app(RfqTriageService::class)->approve($rfq, openReqAdmin());

    expect(RfqCompany::where('rfq_id', $rfq->id)->exists())->toBeFalse();
});

it('caps auto-routing at timber.rfq.auto_route_max', function () {
    config(['timber.rfq.auto_route_max' => 2]);
    $species = Species::factory()->create();
    openReqSupplier($species);
    openReqSupplier($species);
    openReqSupplier($species);

    $rfq = openReqRfq($species);
    app(RfqTriageService::class)->approve($rfq, openReqAdmin());

    expect(RfqCompany::where('rfq_id', $rfq->id)->count())->toBe(2);
});

it('auto-approves and routes a clean RFQ when the buyer verifies their email', function () {
    $species = Species::factory()->create();
    [, $company] = openReqSupplier($species);
    $rfq = openReqRfq($species, ['email_verified_at' => null, 'buyer_email' => 'buyer@acme-timber.test']);

    app(IntakeService::class)->verifyRfq($rfq);

    expect($rfq->fresh()->status)->toBe(RfqStatus::Approved)
        ->and(RfqCompany::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeTrue();
});

it('leaves a flagged RFQ for staff review instead of auto-approving', function () {
    $species = Species::factory()->create();
    openReqSupplier($species);
    $rfq = openReqRfq($species, ['email_verified_at' => null, 'notes' => 'see http://example.test']);

    app(IntakeService::class)->verifyRfq($rfq);

    expect($rfq->fresh()->status)->toBe(RfqStatus::New)
        ->and(RfqCompany::where('rfq_id', $rfq->id)->exists())->toBeFalse();
});

it('does not auto-approve when the toggle is off', function () {
    config(['timber.rfq.auto_approve_low_risk' => false]);
    $species = Species::factory()->create();
    $rfq = openReqRfq($species, ['email_verified_at' => null, 'buyer_email' => 'buyer@acme-timber.test']);

    app(IntakeService::class)->verifyRfq($rfq);

    expect($rfq->fresh()->status)->toBe(RfqStatus::New);
});

/* ----------------------------------------------------------- board (API) */

it('lists matching open requests on the supplier board without buyer contact details', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $sapele = Species::factory()->create();
    $iroko = Species::factory()->create();
    [$user] = openReqSupplier($sapele);
    $match = openReqRfq($sapele, ['status' => RfqStatus::Approved, 'buyer_email' => 'secret@buyer.test', 'buyer_name' => 'Secret Buyer']);
    openReqRfq($iroko, ['status' => RfqStatus::Approved]);
    openReqRfq($sapele, ['status' => RfqStatus::New]);
    openReqRfq($sapele, ['status' => RfqStatus::Approved, 'type' => RfqType::Transport]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.reference'))->toBe($match->reference_code)
        ->and($response->json('data.0'))->not->toHaveKeys(['buyer_email', 'buyer_name', 'buyer_company', 'notes'])
        ->and($response->getContent())->not->toContain('secret@buyer.test')
        ->and($response->getContent())->not->toContain('Secret Buyer');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board?type=transport')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('shows transport requests only to logistics companies', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$logistics] = openReqSupplier($species, ['type' => OrganisationType::Logistics]);
    $rfq = openReqRfq($species, ['status' => RfqStatus::Approved, 'type' => RfqType::Transport]);

    $this->actingAs($logistics, 'sanctum')->getJson('/api/v1/supplier/rfq-board?type=transport')
        ->assertOk()->assertJsonPath('data.0.reference', $rfq->reference_code);
});

it('hides the board from a company whose plan lacks leads_receive', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    $plan = Plan::factory()->create(['slug' => 'no-leads-test', 'features' => ['leads_receive' => false]]);
    [$user] = openReqSupplier($species, ['plan_id' => $plan->id]);
    $rfq = openReqRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfq-board/'.$rfq->reference_code.'/express-interest')->assertNotFound();
});

it('self-routes on express-interest and then reveals the routed view', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user, $company] = openReqSupplier($species);
    $rfq = openReqRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfq-board/'.$rfq->reference_code.'/express-interest')
        ->assertOk()->assertJsonPath('data.routing.status', 'sent');

    expect(RfqCompany::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeTrue();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board')->assertJsonCount(0, 'data');
});

it('lets a supplier quote straight from the board, self-routing first', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user, $company] = openReqSupplier($species);
    $rfq = openReqRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', [
        'currency' => 'USD',
        'items' => [['description' => 'Sawn', 'quantity' => 20, 'unit' => 'm3', 'unit_price' => 150]],
    ])->assertCreated();

    expect(Quote::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeTrue();
});

it('never auto-routes or lists a supplier-directed (chat) RFQ', function () {
    $species = Species::factory()->create();
    [$user] = openReqSupplier($species);
    $rfq = openReqRfq($species, ['source' => 'chat']);

    app(RfqTriageService::class)->approve($rfq, openReqAdmin());

    expect(RfqCompany::where('rfq_id', $rfq->id)->exists())->toBeFalse();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board')->assertJsonCount(0, 'data');
});

it('still 404s a quote on an RFQ that is neither routed nor on the board', function () {
    $sapele = Species::factory()->create();
    $iroko = Species::factory()->create();
    [$user] = openReqSupplier($sapele);
    $rfq = openReqRfq($iroko, ['status' => RfqStatus::Approved]);
    RfqCompany::query()->delete();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', [
        'currency' => 'USD',
        'items' => [['description' => 'Sawn', 'quantity' => 20, 'unit' => 'm3', 'unit_price' => 150]],
    ])->assertNotFound();
});

/* ------------------------------------------------------------- web board */

it('renders the exporter Buyer requests page with matching requests', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user] = openReqSupplier($species);
    $rfq = openReqRfq($species, ['status' => RfqStatus::Approved, 'buyer_email' => 'secret@buyer.test']);

    $this->actingAs($user)->get('/dashboard/buyer-requests')
        ->assertOk()
        ->assertSee($rfq->reference_code)
        ->assertDontSee('secret@buyer.test');

    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    Livewire::actingAs($user)->test(BuyerRequests::class)
        ->callTableAction('quote', $rfq)
        ->assertRedirect();

    expect(RfqCompany::where('rfq_id', $rfq->id)->exists())->toBeTrue();
});

/* -------------------------------------------------------------- messaging */

it('lets a buyer message a free-plan company (no plan gate on messaging)', function () {
    $species = Species::factory()->create();
    [, $company] = openReqSupplier($species);
    expect($company->plan->slug)->toBe('free');

    $buyer = User::factory()->create();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->getKey(), 'body' => 'Do you stock Sapele?'])
        ->assertCreated();

    expect(Conversation::findOrFail($response->json('data.id'))->company_id)->toBe($company->getKey());
});
