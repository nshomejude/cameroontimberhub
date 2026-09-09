<?php

use App\Domain\Identity\Commands\ApproveVerificationCommand;
use App\Domain\Identity\Events\CompanyVerified;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\VerificationService;
use App\Support\Bus\CommandBus;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Regression parity + outbox/webhook coverage for App\Domain\Identity\
 * Commands\ApproveVerificationHandler (architecture plan, Phase 4: Identity
 * & Access). Mirrors tests/Feature/OutboxEventTest.php and
 * tests/Feature/WebhookDeliveryTest.php's patterns.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
});

function verificationDocFor(Company $company, string $typeKey): CompanyDocument
{
    $type = DocumentType::where('key', $typeKey)->firstOrFail();

    return $company->documents()->create([
        'document_type_id' => $type->id,
        'original_filename' => $typeKey.'.pdf',
        'storage_path' => 'x/'.$typeKey.'.pdf',
        'disk' => 'documents',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'status' => \App\Enums\DocumentStatus::Pending,
        'visibility' => \App\Enums\DocumentVisibility::Private,
    ]);
}

it('produces the same result as calling VerificationService::approve() directly', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'logo_path' => 'l.png',
        'region' => 'Centre',
        'description' => str_repeat('timber ', 12),
    ]);
    $doc = verificationDocFor($company, 'business_registration');
    $admin = User::factory()->create();
    $vs = app(VerificationService::class);

    $request = $vs->submit($company, ['verified_company']);
    $vs->startReview($request->fresh(), $admin);
    $vs->approveDocument($doc->fresh(), $admin);

    $result = app(CommandBus::class)->dispatch(new ApproveVerificationCommand(
        verificationRequestId: $request->getKey(),
        actingUserId: $admin->getKey(),
    ));

    expect($result['issued'])->toContain('verified_company')
        ->and($company->fresh()->status)->toBe(CompanyStatus::Verified)
        ->and($company->activeBadges()->count())->toBe(1);
});

it('records the company.verified outbox event transactionally when the company newly becomes verified', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'logo_path' => 'l.png',
        'region' => 'Centre',
        'description' => str_repeat('timber ', 12),
    ]);
    $doc = verificationDocFor($company, 'business_registration');
    $admin = User::factory()->create();
    $vs = app(VerificationService::class);

    $request = $vs->submit($company, ['verified_company']);
    $vs->startReview($request->fresh(), $admin);
    $vs->approveDocument($doc->fresh(), $admin);

    app(CommandBus::class)->dispatch(new ApproveVerificationCommand(
        verificationRequestId: $request->getKey(),
        actingUserId: $admin->getKey(),
    ));

    $row = OutboxEvent::query()->where('event_type', 'company.verified')->where('aggregate_id', (string) $company->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->payload['company_id'])->toBe($company->id)
        ->and($row->payload['verification_request_id'])->toBe($request->id);
});

it('rolls back the outbox row together with the state change on a forced failure', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'logo_path' => 'l.png',
        'region' => 'Centre',
        'description' => str_repeat('timber ', 12),
    ]);
    $doc = verificationDocFor($company, 'business_registration');
    $admin = User::factory()->create();
    $vs = app(VerificationService::class);

    $request = $vs->submit($company, ['verified_company']);
    $vs->startReview($request->fresh(), $admin);
    $vs->approveDocument($doc->fresh(), $admin);

    try {
        DB::transaction(function () use ($request, $admin) {
            app(CommandBus::class)->dispatch(new ApproveVerificationCommand(
                verificationRequestId: $request->getKey(),
                actingUserId: $admin->getKey(),
            ));

            throw new RuntimeException('simulated failure after approval, before commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxEvent::query()->where('event_type', 'company.verified')->exists())->toBeFalse()
        ->and($company->fresh()->status)->not->toBe(CompanyStatus::Verified);
});

it('does not record company.verified when the company was already verified', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'logo_path' => 'l.png',
        'region' => 'Centre',
        'description' => str_repeat('timber ', 12),
    ]);
    $doc = verificationDocFor($company, 'business_registration');
    $admin = User::factory()->create();
    $vs = app(VerificationService::class);

    $firstRequest = $vs->submit($company, ['verified_company']);
    $vs->startReview($firstRequest->fresh(), $admin);
    $vs->approveDocument($doc->fresh(), $admin);
    app(CommandBus::class)->dispatch(new ApproveVerificationCommand(
        verificationRequestId: $firstRequest->getKey(),
        actingUserId: $admin->getKey(),
    ));

    expect($company->fresh()->status)->toBe(CompanyStatus::Verified);
    OutboxEvent::query()->truncate();

    // A second verification request on the already-verified company (e.g.
    // requesting an additional badge) must not re-fire company.verified.
    $doc2 = verificationDocFor($company, 'export_permit');
    $secondRequest = $vs->submit($company, ['verified_exporter']);
    $vs->startReview($secondRequest->fresh(), $admin);
    $vs->approveDocument($doc2->fresh(), $admin, now()->addYear()->toDateString());

    app(CommandBus::class)->dispatch(new ApproveVerificationCommand(
        verificationRequestId: $secondRequest->getKey(),
        actingUserId: $admin->getKey(),
    ));

    expect(OutboxEvent::query()->where('event_type', 'company.verified')->exists())->toBeFalse();
});

it('relays the company.verified outbox row by dispatching the CompanyVerified domain event', function () {
    Event::fake([CompanyVerified::class]);

    $company = Company::factory()->create();

    $row = OutboxEvent::query()->create([
        'aggregate_type' => 'Company',
        'aggregate_id' => (string) $company->id,
        'event_type' => 'company.verified',
        'payload' => ['company_id' => $company->id, 'verification_request_id' => 999],
        'occurred_at' => now(),
        'published_at' => null,
        'attempts' => 0,
        'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(CompanyVerified::class, fn (CompanyVerified $event) => $event->companyId === $company->id && $event->verificationRequestId === 999);

    expect($row->fresh()->published_at)->not->toBeNull();
});

it('dispatches a DeliverWebhookJob to a company.verified subscriber', function () {
    Bus::fake();

    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['company.verified'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'company.verified',
        'aggregate_type' => 'Company',
        'aggregate_id' => (string) $company->id,
        'payload' => ['company_id' => $company->id, 'verification_request_id' => 1],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription, $company) {
        return $job->subscriptionId === $subscription->id
            && $job->eventType === 'company.verified'
            && (int) $job->payload['company_id'] === $company->id;
    });
});
