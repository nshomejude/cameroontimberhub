<?php

use App\Enums\ConsentPurpose;
use App\Mail\InquiryVerificationMail;
use App\Mail\RfqVerificationMail;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Lead;
use App\Models\Rfq;
use App\Models\SuspiciousEvent;
use App\Models\User;
use App\Notifications\RfqRoutedToExporter;
use App\Services\IntakeService;
use App\Services\LeadFlowService;
use App\Services\RfqTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function rfqPayload(array $overrides = []): array
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

function makeRfq(array $attributes = []): Rfq
{
    return Rfq::create(array_merge([
        'reference_code' => 'RFQ-2026-'.Str::upper(Str::random(5)),
        'buyer_name' => 'Buyer',
        'buyer_email' => 'b@acme.test',
        'status' => 'new',
        'visibility' => 'public',
    ], $attributes));
}

it('serves the public request-quote form', function () {
    $this->get(route('rfq.create'))->assertOk()->assertSee('Request a quote');
});

it('creates an unverified RFQ with a line item and sends a verification email', function () {
    Mail::fake();

    $this->post(route('rfq.store'), rfqPayload())->assertRedirect(route('rfq.thanks'));

    $rfq = Rfq::first();
    expect($rfq)->not->toBeNull()
        ->and($rfq->email_verified_at)->toBeNull()
        ->and($rfq->items()->count())->toBe(1)
        ->and($rfq->reference_code)->toStartWith('RFQ-');

    Mail::assertSent(RfqVerificationMail::class);
});

it('persists a Consent row from the wizard checkbox instead of discarding it', function () {
    $this->withServerVariables(['HTTP_USER_AGENT' => 'PestTestAgent/1.0'])
        ->post(route('rfq.store'), rfqPayload());

    $rfq = Rfq::firstOrFail();

    expect($rfq->consents)->toHaveCount(1);

    $consent = $rfq->consents->first();

    expect($consent->purpose)->toBe(ConsentPurpose::RfqExporterSharing)
        ->and($consent->scope)->toBe(['shared_with' => 'verified_exporters', 'contact_channel' => 'email'])
        ->and($consent->granted_at)->not->toBeNull()
        ->and($consent->revoked_at)->toBeNull()
        ->and($consent->evidence['user_agent'])->toBe('PestTestAgent/1.0')
        ->and($consent->evidence['ip_address'])->not->toBeNull();
});

it('silently drops honeypot submissions and records a suspicious event', function () {
    Mail::fake();

    $this->post(route('rfq.store'), rfqPayload(['website' => 'http://spam']))->assertRedirect(route('rfq.thanks'));

    expect(Rfq::count())->toBe(0)
        ->and(SuspiciousEvent::where('event_type', 'honeypot_triggered')->count())->toBe(1);

    Mail::assertNothingSent();
});

it('verifies an RFQ via the signed link and rejects an unsigned hit', function () {
    $rfq = makeRfq();

    $this->get(app(IntakeService::class)->rfqVerifyUrl($rfq))->assertOk();
    expect($rfq->fresh()->email_verified_at)->not->toBeNull();

    $this->get(route('rfq.verify', ['rfq' => $rfq->id, 'h' => 'tampered']))->assertForbidden();
});

it('shows only verified RFQs in the admin queue', function () {
    makeRfq(['email_verified_at' => now(), 'buyer_name' => 'Verified Buyer']);
    makeRfq(['buyer_name' => 'Unverified Buyer']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin/rfqs')
        ->assertOk()
        ->assertSee('Verified Buyer')
        ->assertDontSee('Unverified Buyer');
});

it('routes an approved RFQ to an exporter, creating one lead, idempotently', function () {
    Notification::fake();
    $company = Company::factory()->publiclyVisible()->create();
    $member = User::factory()->create();
    $member->companies()->attach($company, ['role' => 'owner']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $rfq = makeRfq(['email_verified_at' => now()]);
    $triage = app(RfqTriageService::class);
    $triage->startReview($rfq, $admin);
    $triage->approve($rfq->fresh(), $admin);

    $routed = $triage->route($rfq->fresh(), [$company->id], $admin, app(LeadFlowService::class));

    expect($routed)->toBe(1)
        ->and($rfq->routings()->count())->toBe(1)
        ->and(Lead::where('company_id', $company->id)->where('source', 'rfq')->count())->toBe(1)
        ->and($triage->route($rfq->fresh(), [$company->id], $admin, app(LeadFlowService::class)))->toBe(0);

    Notification::assertSentTo($member, RfqRoutedToExporter::class);
});

it('creates a lead when a company inquiry is verified', function () {
    Mail::fake();
    $company = Company::factory()->publiclyVisible()->create();

    $this->post(route('inquiry.store', $company->slug), [
        'name' => 'Bob Buyer',
        'email' => 'bob@acme.test',
        'message' => 'Hello, I am interested in your sawn timber for export to Europe.',
        'consent' => '1',
        'form_rendered_at' => now()->subSeconds(30)->timestamp,
    ])->assertRedirect();

    $inquiry = CompanyInquiry::first();
    expect($inquiry)->not->toBeNull();
    Mail::assertSent(InquiryVerificationMail::class);

    app(IntakeService::class)->verifyInquiry($inquiry);
    expect(Lead::where('company_inquiry_id', $inquiry->id)->count())->toBe(1);
});

it('lets an exporter open their leads inbox', function () {
    $company = Company::factory()->create();
    $member = User::factory()->create();
    $member->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($member)->get('/dashboard/leads')->assertOk();
});
