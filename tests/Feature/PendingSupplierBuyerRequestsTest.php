<?php

use App\Enums\CompanyStatus;
use App\Enums\LeadStatus;
use App\Enums\RfqStatus;
use App\Enums\RfqType;
use App\Filament\Exporter\Pages\BuyerRequests;
use App\Filament\Exporter\Resources\Leads\LeadResource;
use App\Filament\Exporter\Resources\Quotes\QuoteResource;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\User;
use App\Notifications\CompanyVerifiedNotification;
use App\Notifications\RfqRoutedToExporter;
use App\Services\LeadFlowService;
use App\Services\QuoteService;
use App\Services\RfqRoutingResult;
use App\Services\RfqTriageService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Owner decision: quote requests reach suppliers pending verification, but
 * they cannot act on them until verified (Company::canRespondToBuyers()).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
    Mail::fake();
    Notification::fake();
});

function pendingSupplier(Species $species, CompanyStatus $status = CompanyStatus::Pending): array
{
    $company = Company::factory()->create(['status' => $status]);
    $company->species()->attach($species);
    $user = User::factory()->create();
    $company->users()->attach($user);

    return [$user, $company->fresh()];
}

function pendingRfq(Species $species, array $attributes = []): Rfq
{
    $rfq = Rfq::factory()->verified()->create(array_merge([
        'status' => RfqStatus::New,
        'type' => RfqType::Export,
        'buyer_name' => 'Jane Buyer',
        'buyer_email' => 'jane@buyer.test',
    ], $attributes));
    $rfq->items()->create(['species_id' => $species->id, 'species_text' => $species->common_name, 'form' => 'sawn', 'quantity' => 20, 'unit' => 'm3']);

    return $rfq->fresh();
}

function pendingAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

const QUOTE_BODY = ['currency' => 'USD', 'items' => [['description' => 'Sawn', 'quantity' => 20, 'unit' => 'm3', 'unit_price' => 150]]];

it('centralises the rule on the company', function () {
    expect(Company::factory()->make(['status' => CompanyStatus::Verified])->canRespondToBuyers())->toBeTrue()
        ->and(Company::factory()->make(['status' => CompanyStatus::Pending])->canRespondToBuyers())->toBeFalse()
        ->and(Company::factory()->make(['status' => CompanyStatus::Pending])->canReceiveBuyerRequests())->toBeTrue()
        ->and(Company::factory()->make(['status' => CompanyStatus::Draft])->canReceiveBuyerRequests())->toBeFalse();
});

it('auto-routes an RFQ to a pending company, creating the lead and notifying it', function () {
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species);

    $rfq = pendingRfq($species);
    app(RfqTriageService::class)->approve($rfq, pendingAdmin());

    expect(RfqCompany::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeTrue()
        ->and(Lead::where('rfq_id', $rfq->id)->where('company_id', $company->id)->exists())->toBeTrue();
    Notification::assertSentTo($user, RfqRoutedToExporter::class, fn (RfqRoutedToExporter $n) => $n->toArray($user)['can_respond'] === false);
});

it('never routes to draft, suspended, rejected or archived companies', function (CompanyStatus $status) {
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species, $status);

    $rfq = pendingRfq($species);
    app(RfqTriageService::class)->approve($rfq, pendingAdmin());

    expect(RfqCompany::where('company_id', $company->id)->exists())->toBeFalse();
    Notification::assertNotSentTo($user, RfqRoutedToExporter::class);

    // Manual routing refuses too, with a reason.
    $result = app(RfqTriageService::class)->routeDetailed($rfq->fresh(), [$company->id], pendingAdmin(), app(LeadFlowService::class));
    expect($result->routed)->toBe(0)
        ->and($result->skipped[0]['reason'])->toBe(RfqRoutingResult::REASON_INELIGIBLE_STATUS);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board')->assertOk()->assertJsonCount(0, 'data');
})->with([CompanyStatus::Draft, CompanyStatus::Suspended, CompanyStatus::Rejected, CompanyStatus::Archived]);

it('shows a pending company the board, flagged as not able to respond', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user] = pendingSupplier($species);
    $rfq = pendingRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfq-board')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $rfq->reference_code)
        ->assertJsonPath('data.0.can_respond', false)
        ->assertJsonPath('data.0.contact_locked', true);
});

it('refuses quoting and express-interest from a pending company with company_verification_required', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species);
    $rfq = pendingRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfq-board/'.$rfq->reference_code.'/express-interest')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'company_verification_required')
        ->assertJsonPath('error.message', 'Complete your company verification to respond to buyer requests.');

    // Even once routed, the quote endpoint refuses.
    app(RfqTriageService::class)->routeDetailed($rfq, [$company->id], pendingAdmin(), app(LeadFlowService::class));
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', QUOTE_BODY)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'company_verification_required');

    expect(Quote::where('company_id', $company->id)->exists())->toBeFalse();
    expect(fn () => app(QuoteService::class)->assertQuotable($rfq, $company))
        ->toThrow(RuntimeException::class, Company::VERIFICATION_REQUIRED_MESSAGE);
});

it('hides buyer contact on the routed RFQ and lead for a pending company, and locks lead updates', function () {
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species);
    $rfq = pendingRfq($species);
    app(RfqTriageService::class)->approve($rfq, pendingAdmin());
    $lead = Lead::where('company_id', $company->id)->firstOrFail();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfqs/'.$rfq->reference_code)
        ->assertOk()
        ->assertJsonPath('data.buyer_name', null)
        ->assertJsonPath('data.buyer_email', null)
        ->assertJsonPath('data.contact_locked', true)
        ->assertJsonPath('data.can_respond', false)
        ->assertDontSee('jane@buyer.test');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/leads/'.$lead->id)
        ->assertOk()
        ->assertJsonPath('data.buyer_email', null)
        ->assertJsonPath('data.contact_locked', true);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/supplier/leads/'.$lead->id, ['status' => LeadStatus::cases()[1]->value])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'company_verification_required');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.company.can_respond_to_buyers', false);
});

it('locks the exporter panel actions for a pending company', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species);
    $rfq = pendingRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user)->get('/dashboard/buyer-requests')
        ->assertOk()
        ->assertSee($rfq->reference_code)
        ->assertSee('Complete your company verification to respond to buyer requests.');

    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    Livewire::actingAs($user)->test(BuyerRequests::class)
        ->assertTableActionDisabled('quote', $rfq);

    $this->actingAs($user);
    expect(QuoteResource::canCreate())->toBeFalse();

    app(RfqTriageService::class)->routeDetailed($rfq, [$company->id], pendingAdmin(), app(LeadFlowService::class));
    $lead = Lead::where('company_id', $company->id)->firstOrFail();
    expect(LeadResource::canEdit($lead))->toBeFalse();
    $this->actingAs($user)->get(LeadResource::getUrl('edit', ['record' => $lead], panel: 'exporter'))->assertForbidden();
    $this->actingAs($user)->get(LeadResource::getUrl('index', panel: 'exporter'))
        ->assertOk()
        ->assertDontSee('Jane Buyer');
});

it('leaves a verified company unaffected', function () {
    config(['timber.rfq.auto_route_on_approval' => false]);
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species, CompanyStatus::Verified);
    $rfq = pendingRfq($species, ['status' => RfqStatus::Approved]);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', QUOTE_BODY)->assertCreated();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfqs/'.$rfq->reference_code)
        ->assertJsonPath('data.buyer_email', 'jane@buyer.test')
        ->assertJsonPath('data.can_respond', true)
        ->assertJsonPath('data.contact_locked', false);

    $this->actingAs($user);
    expect(QuoteResource::canCreate())->toBeTrue();
});

it('tells a newly verified company how many buyer requests are waiting', function () {
    $species = Species::factory()->create();
    [$user, $company] = pendingSupplier($species);
    app(RfqTriageService::class)->approve(pendingRfq($species), pendingAdmin());

    $company->update(['status' => CompanyStatus::Verified]);
    $mail = (new CompanyVerifiedNotification($company->fresh()))->toMail($user);

    expect(implode(' ', $mail->introLines))->toContain('You can now respond to 1 buyer request waiting for you.');
});
