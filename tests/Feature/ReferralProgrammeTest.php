<?php

use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\ReferralEarningStatus;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ReferralEarning;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Notifications\ReferralCommissionEarnedNotification;
use App\Notifications\ReferralSignedUpNotification;
use App\Services\Referrals\ReferralService;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Referral programme: codes, sign-up attribution, self-referral guard,
 * one-time 10% commission on the referred company's FIRST subscription
 * payment, and the /api/v1/referrals* shapes the mobile app renders.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
});

function referrer(array $attrs = []): User
{
    return User::factory()->create(array_merge(['email' => 'ref'.uniqid().'@gmail.com'], $attrs));
}

function registerViaApi(array $overrides = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/register', array_merge([
        'account_type' => 'supplier',
        'name' => 'Jean Dupont',
        'email' => 'new'.uniqid().'@yahoo.fr',
        'password' => 'Str0ng-Passw0rd!',
        'company_name' => 'Bois Dupont SARL',
    ], $overrides));
}

function completeReferralPayment(Company $company, float $amount = 100000, string $currency = 'XAF'): Payment
{
    $plan = Plan::factory()->create(['billing_period' => 'monthly']);
    $payment = Payment::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'amount' => $amount,
        'currency' => $currency,
        'provider_reference' => 'ref-'.Str::random(8),
    ]);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    app(RelayOutboxEventsJob::class)->handle();

    return $payment->fresh();
}

function referredCompany(User $referrer): Company
{
    $email = 'new'.uniqid().'@yahoo.fr';
    registerViaApi(['email' => $email, 'referral_code' => app(ReferralService::class)->codeFor($referrer)])->assertCreated();

    return User::where('email', $email)->firstOrFail()->companies()->firstOrFail();
}

it('generates a unique CTH-XXXXXX code lazily and keeps it stable', function () {
    $service = app(ReferralService::class);
    $a = referrer();
    $b = referrer();

    $codeA = $service->codeFor($a);

    expect($codeA)->toMatch('/^CTH-[A-Z2-9]{6}$/')
        ->and($service->codeFor($a->fresh()))->toBe($codeA)
        ->and($service->codeFor($b))->not->toBe($codeA)
        ->and($service->codeFor(Company::factory()->create()))->toMatch('/^CTH-[A-Z2-9]{6}$/');

    $codes = collect(range(1, 300))->map(fn () => ReferralService::generateCode());
    expect($codes->unique()->count())->toBe(300);
});

it('backfills codes for everyone via the artisan command', function () {
    User::factory()->count(3)->create();

    $this->artisan('referrals:backfill-codes')->assertSuccessful();

    expect(User::whereNull('referral_code')->count())->toBe(0);
});

it('links the referrer on API registration with a code and notifies them', function () {
    Notification::fake();
    $ref = referrer();
    $code = app(ReferralService::class)->codeFor($ref);
    $email = 'new'.uniqid().'@yahoo.fr';

    registerViaApi(['email' => $email, 'referral_code' => strtolower($code)])->assertCreated();

    $user = User::where('email', $email)->firstOrFail();
    $company = $user->companies()->first();

    expect($user->referred_by_user_id)->toBe($ref->id)
        ->and($user->referred_at)->not->toBeNull()
        ->and($company->referred_by_user_id)->toBe($ref->id);

    Notification::assertSentTo($ref, ReferralSignedUpNotification::class, function ($n) use ($ref) {
        return $n->toArray($ref)['screen'] === 'referral';
    });
});

it('links the referrer on web registration and prefills ?ref=', function () {
    $ref = referrer();
    $code = app(ReferralService::class)->codeFor($ref);

    $this->get('/register?ref='.$code)->assertOk()->assertSee($code);

    $email = 'web'.uniqid().'@yahoo.fr';
    $this->post(route('register.store'), [
        'account_type' => 'buyer', 'name' => 'Web Buyer', 'email' => $email,
        'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1', 'referral_code' => $code,
    ])->assertRedirect();

    expect(User::where('email', $email)->first()->referred_by_user_id)->toBe($ref->id);
});

it('rejects an unknown referral code', function () {
    registerViaApi(['referral_code' => 'CTH-NOPE00'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['referral_code']]]);
});

it('rejects self-referral by same email domain for non-free-mail domains', function () {
    $ref = referrer(['email' => 'boss@boisdupont.cm']);
    $code = app(ReferralService::class)->codeFor($ref);

    registerViaApi(['email' => 'clerk@boisdupont.cm', 'referral_code' => $code])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['referral_code']]]);

    // Shared free-mail domain is fine.
    $ref2 = referrer(['email' => 'a'.uniqid().'@gmail.com']);
    registerViaApi(['email' => 'b'.uniqid().'@gmail.com', 'referral_code' => app(ReferralService::class)->codeFor($ref2)])
        ->assertCreated();
});

it('rejects self-referral with the same user or same company', function () {
    $service = app(ReferralService::class);
    $ref = referrer();
    $company = Company::factory()->create(['created_by' => $ref->id]);
    $company->users()->attach($ref, ['role' => 'owner', 'is_primary' => true]);
    $resolved = $service->resolveReferrer($service->codeFor($company));

    expect($resolved['user']->id)->toBe($ref->id)
        ->and($service->selfReferralReason($resolved, 'x@yahoo.fr', $ref))->toBe('same_user')
        ->and($service->selfReferralReason($resolved, 'x@yahoo.fr', null, $company))->toBe('same_company')
        ->and($service->selfReferralReason($resolved, strtoupper($ref->email)))->toBe('same_user');
});

it('creates exactly one 10% pending earning on the first subscription payment and notifies', function () {
    Notification::fake();
    $ref = referrer();
    $company = referredCompany($ref);

    $payment = completeReferralPayment($company, 150000);
    // Re-relay (double webhook) is idempotent.
    app(RelayOutboxEventsJob::class)->handle();

    $earnings = ReferralEarning::all();
    expect($earnings)->toHaveCount(1);

    $e = $earnings->first();
    expect($e->referrer_user_id)->toBe($ref->id)
        ->and($e->referred_company_id)->toBe($company->id)
        ->and($e->payment_id)->toBe($payment->id)
        ->and((float) $e->amount)->toBe(15000.0)
        ->and($e->currency)->toBe('XAF')
        ->and($e->status)->toBe(ReferralEarningStatus::Pending);

    Notification::assertSentTo($ref, ReferralCommissionEarnedNotification::class);
});

it('pays nothing on renewals', function () {
    $ref = referrer();
    $company = referredCompany($ref);

    completeReferralPayment($company, 100000);
    completeReferralPayment($company, 100000);
    completeReferralPayment($company, 100000);

    expect(ReferralEarning::count())->toBe(1);
});

it('pays nothing when the programme is disabled, or for unreferred companies', function () {
    ReferralSetting::current()->update(['enabled' => false]);
    $company = referredCompany(referrer());
    completeReferralPayment($company);

    ReferralSetting::current()->update(['enabled' => true]);
    completeReferralPayment(Company::factory()->create());

    expect(ReferralEarning::count())->toBe(0);
});

it('uses the admin-configured rate', function () {
    ReferralSetting::current()->update(['rate_percent' => 12.5]);
    $company = referredCompany(referrer());

    completeReferralPayment($company, 80000);

    expect((float) ReferralEarning::first()->amount)->toBe(10000.0);
});

it('moves an earning pending → approved → paid', function () {
    $company = referredCompany(referrer());
    completeReferralPayment($company);
    $e = ReferralEarning::first();

    $e->markApproved();
    expect($e->fresh()->status)->toBe(ReferralEarningStatus::Approved);
    $e->fresh()->markPaid();
    expect($e->fresh()->status)->toBe(ReferralEarningStatus::Paid)->and($e->fresh()->paid_at)->not->toBeNull();
});

it('requires auth for the referral endpoints', function () {
    $this->getJson('/api/v1/referrals/me')->assertUnauthorized();
    $this->getJson('/api/v1/referrals')->assertUnauthorized();
    $this->getJson('/api/v1/referrals/earnings')->assertUnauthorized();
});

it('returns the /referrals/me shape', function () {
    $ref = referrer();
    $company = referredCompany($ref);
    referredCompany($ref);
    completeReferralPayment($company, 100000);
    $this->withToken($ref->createToken('test')->plainTextToken);

    $res = $this->getJson('/api/v1/referrals/me')->assertOk()->assertJsonStructure(['data' => [
        'code', 'share_url',
        'stats' => ['invited', 'signed_up', 'qualified', 'earned_label', 'pending_label', 'paid_label'],
        'terms' => ['summary', 'commission_percent', 'basis', 'one_time'],
    ]]);

    $code = $ref->fresh()->referral_code;
    expect($res->json('data.code'))->toBe($code)
        ->and($res->json('data.share_url'))->toContain('/register?ref='.$code)
        ->and($res->json('data.stats.signed_up'))->toBe(2)
        ->and($res->json('data.stats.qualified'))->toBe(1)
        ->and($res->json('data.stats.earned_label'))->toBe('XAF 10,000')
        ->and($res->json('data.stats.pending_label'))->toBe('XAF 10,000')
        ->and($res->json('data.stats.paid_label'))->toBe('XAF 0')
        ->and($res->json('data.terms.commission_percent'))->toEqual(10)
        ->and($res->json('data.terms.basis'))->toBe('subscription')
        ->and($res->json('data.terms.one_time'))->toBeTrue();
});

it('returns the /referrals list and /referrals/earnings shapes, scoped to the viewer', function () {
    $ref = referrer();
    $company = referredCompany($ref);
    referredCompany($ref);
    referredCompany(referrer()); // someone else's
    completeReferralPayment($company, 100000);
    $this->withToken($ref->createToken('test')->plainTextToken);

    $list = $this->getJson('/api/v1/referrals')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'name_masked', 'joined_at', 'status', 'status_label', 'earned_label']]]);

    expect(collect($list->json('data'))->pluck('name_masked')->unique()->all())->toBe(['Jean D.'])
        ->and(collect($list->json('data'))->pluck('status')->sort()->values()->all())->toBe(['qualified', 'signed_up']);

    $this->getJson('/api/v1/referrals/earnings')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['id', 'source_reference', 'amount_label', 'status', 'status_label', 'at']]])
        ->assertJsonPath('data.0.amount_label', 'XAF 10,000')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.status_label', 'Pending');
});

it('renders the admin referral pages for a super admin and hides them from members', function () {
    $company = referredCompany(referrer());
    completeReferralPayment($company);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get('/admin/referrals')->assertOk()->assertSee('Jean Dupont');
    $this->actingAs($admin)->get('/admin/referral-earnings')->assertOk()->assertSee('SUB-PAY-');
    $this->actingAs($admin)->get('/admin/referral-settings')->assertOk();

    $member = User::factory()->create();
    $this->actingAs($member)->get('/admin/referral-earnings')->assertForbidden();
});
