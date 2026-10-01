<?php

use App\Enums\ReferralEarningStatus;
use App\Filament\Exporter\Pages\Referrals;
use App\Filament\Resources\ReferralEarnings\Pages\ListReferralEarnings;
use App\Models\Company;
use App\Models\ReferralEarning;
use App\Models\ReferralPayoutProfile;
use App\Models\User;
use App\Services\Referrals\ReferralPayoutService;
use App\Services\Referrals\ReferralService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Exporter panel → Referrals (/dashboard/referrals): a supplier referrer's
 * link, funnel, totals and commissions, and their payout details — the
 * PayPal email plus optional Mobile Money / bank details for manual (XAF)
 * payouts, shown masked to the referrer and in full to finance on the admin
 * Referral earnings table. Also the matching API / account-settings fields.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
});

function refPageMember(): User
{
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(), ['role' => 'member', 'is_primary' => true]);

    return $user;
}

function refPageEarning(User $referrer, array $attrs = []): ReferralEarning
{
    return ReferralEarning::create(array_merge([
        'referrer_user_id' => $referrer->id,
        'referred_company_id' => Company::factory()->create()->id,
        'source_reference' => 'SUB-PAY-'.random_int(1000, 99999),
        'basis' => 'subscription',
        'base_amount' => 100000,
        'rate_percent' => 10,
        'amount' => 10000,
        'currency' => 'XAF',
        'status' => ReferralEarningStatus::Approved,
        'approved_at' => now(),
    ], $attrs));
}

function refPageReferred(User $referrer, ?Company $company = null): User
{
    $u = User::factory()->create();
    $u->forceFill(['referred_by_user_id' => $referrer->id, 'referred_at' => now()])->save();
    if ($company) {
        $u->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);
    }

    return $u;
}

/* -------------------------------------------------------------- page */

it('renders the referrals page for any company member with link, stats, totals and earnings', function () {
    $member = refPageMember();

    // Funnel: one signed up only, one verified, one qualified.
    refPageReferred($member);
    refPageReferred($member, Company::factory()->verified()->create());
    $qualifiedCompany = Company::factory()->create();
    refPageReferred($member, $qualifiedCompany);

    refPageEarning($member, ['referred_company_id' => $qualifiedCompany->id, 'source_reference' => 'SUB-PAY-QUAL']);
    refPageEarning($member, ['currency' => 'USD', 'amount' => 12.5, 'status' => ReferralEarningStatus::Paid, 'paid_at' => now()]);
    refPageEarning($member, ['status' => ReferralEarningStatus::Cancelled, 'amount' => 999999]);

    $this->actingAs($member);
    $html = $this->get('/dashboard/referrals')->assertOk()
        ->assertSee($member->fresh()->referral_code)
        ->assertSee(route('register', ['ref' => $member->fresh()->referral_code]))
        ->assertSee('Commissions in XAF are paid by Mobile Money or bank transfer — our finance team will contact you.')
        ->assertSee('SUB-PAY-QUAL')
        ->assertSee('XAF 10,000')
        ->assertSee('USD 12.50')
        ->assertSee('Approved — awaiting payout')
        // A cancelled commission is listed as such but never counted.
        ->assertSee('Cancelled')
        ->assertDontSee('XAF 1,009,999')
        ->getContent();

    expect(app(ReferralService::class)->funnelFor($member))
        ->toBe(['signed_up' => 3, 'verified' => 1, 'qualified' => 1]);
    expect(app(ReferralService::class)->totalsFor($member))->toBe([
        'USD' => ['earned' => 12.5, 'paid' => 12.5, 'pending' => 0.0],
        'XAF' => ['earned' => 10000.0, 'paid' => 0.0, 'pending' => 10000.0],
    ]);
    expect($html)->toContain('Referrals');
});

it('shows the page in French', function () {
    $member = refPageMember();
    $member->forceFill(['locale' => 'fr'])->save();

    $this->actingAs($member)->withSession(['locale' => 'fr'])
        ->get('/dashboard/referrals')->assertOk()
        ->assertSee('Les commissions en XAF sont payées par Mobile Money ou virement bancaire');
});

it('keeps the referrals page behind the company-member panel gate', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard/referrals')->assertForbidden();
});

/* ------------------------------------------------------ payout details */

it('saves and removes the PayPal payout email from the page, masked', function () {
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    $member = refPageMember();
    $this->actingAs($member);

    Livewire::test(Referrals::class)
        ->callAction('setPaypalEmail', data: ['paypal_payout_email' => 'not-an-email'])
        ->assertHasActionErrors(['paypal_payout_email']);

    Livewire::test(Referrals::class)
        ->callAction('setPaypalEmail', data: ['paypal_payout_email' => 'Pay.Me@Example.com'])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertSee('pa****@example.com')
        ->assertDontSee('pay.me@example.com');

    expect(ReferralPayoutProfile::paypalEmailFor($member))->toBe('pay.me@example.com')
        // Encrypted at rest.
        ->and(DB::table('referral_payout_profiles')->where('user_id', $member->id)->value('paypal_email'))
        ->not->toContain('pay.me');

    Livewire::test(Referrals::class)->callAction('removePaypalEmail');
    expect(ReferralPayoutProfile::paypalEmailFor($member))->toBeNull();
});

it('saves manual MoMo / bank details from the page, encrypted, masked and audited', function () {
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    $member = refPageMember();
    $this->actingAs($member);

    Livewire::test(Referrals::class)
        ->callAction('setManualDetails', data: ['manual_payout_details' => str_repeat('x', 501)])
        ->assertHasActionErrors(['manual_payout_details']);

    Livewire::test(Referrals::class)
        ->callAction('setManualDetails', data: ['manual_payout_details' => 'MTN MoMo 677 12 34 56, Jean Dupont'])
        ->assertHasNoActionErrors()
        ->assertSee('MTN MoMo *** ** 34 56, Jean Dupont')
        ->assertDontSee('677 12 34 56');

    expect(ReferralPayoutProfile::manualPayoutDetailsFor($member))->toBe('MTN MoMo 677 12 34 56, Jean Dupont')
        ->and(DB::table('referral_payout_profiles')->where('user_id', $member->id)->value('manual_payout_details'))
        ->not->toContain('677');

    $log = Activity::where('log_name', 'referral_payout')->where('event', 'manual_payout_details_updated')->latest('id')->first();
    expect(json_encode($log->properties))->not->toContain('677 12');

    // The PayPal email is untouched by the manual-details action.
    expect(ReferralPayoutProfile::paypalEmailFor($member))->toBeNull();

    Livewire::test(Referrals::class)->callAction('removeManualDetails');
    expect(ReferralPayoutProfile::manualPayoutDetailsFor($member))->toBeNull();
});

it('masks manual payout details: emails and all but the last four digits', function (?string $in, ?string $out) {
    expect(ReferralPayoutProfile::maskDetails($in))->toBe($out);
})->with([
    'momo' => ['MTN MoMo 677 12 34 56', 'MTN MoMo *** ** 34 56'],
    'bank' => ['Afriland, acct 10005-00001-12345678901', 'Afriland, acct *****-*****-*******8901'],
    'short' => ['Orange 12', 'Orange *2'],
    'email' => ['Orange Money via jean.dupont@example.com', 'Orange Money via je*********@example.com'],
    'no digits' => ['Call me, Jean', 'Call me, Jean'],
    'blank' => ['  ', null],
    'null' => [null, null],
]);

/* -------------------------------------------------------------- admin */

it('shows the manual payout details to finance on the admin earnings table (masked without payments.manage)', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $referrer = User::factory()->create();
    app(ReferralPayoutService::class)->setManualPayoutDetails($referrer, 'MTN MoMo 677 12 34 56');
    $earning = refPageEarning($referrer);

    $finance = User::factory()->create();
    $finance->assignRole('finance_officer');
    $this->actingAs($finance);
    Livewire::test(ListReferralEarnings::class)
        ->assertCanSeeTableRecords([$earning])
        ->assertTableColumnStateSet('manual_payout_details', 'MTN MoMo 677 12 34 56', $earning);

    $billing = User::factory()->create();
    $billing->assignRole('billing_officer');
    $this->actingAs($billing);
    Livewire::test(ListReferralEarnings::class)
        ->assertTableColumnStateSet('manual_payout_details', 'MTN MoMo *** ** 34 56', $earning);
});

/* ---------------------------------------------------------------- API */

it('exposes manual payout details via the API, masked, without disturbing the PayPal email', function () {
    $user = User::factory()->create();
    $this->withToken($user->createToken('t')->plainTextToken);

    $this->patchJson('/api/v1/referrals/payout-settings', ['paypal_payout_email' => 'jean.dupont@example.com'])->assertOk();

    $this->patchJson('/api/v1/referrals/payout-settings', ['manual_payout_details' => 'MTN MoMo 677 12 34 56'])
        ->assertOk()
        ->assertJsonPath('data.manual_payout_details_masked', 'MTN MoMo *** ** 34 56')
        ->assertJsonPath('data.has_manual_payout_details', true)
        ->assertJsonPath('data.has_paypal_email', true);

    $me = $this->getJson('/api/v1/referrals/me')->assertOk()
        ->assertJsonPath('data.payout.manual_payout_details_masked', 'MTN MoMo *** ** 34 56')
        ->assertJsonPath('data.payout.has_manual_payout_details', true);
    expect(json_encode($me->json()))->not->toContain('677 12');

    $this->patchJson('/api/v1/referrals/payout-settings', ['manual_payout_details' => str_repeat('x', 501)])
        ->assertUnprocessable();
    $this->patchJson('/api/v1/referrals/payout-settings', [])
        ->assertUnprocessable();

    $this->patchJson('/api/v1/referrals/payout-settings', ['manual_payout_details' => null])
        ->assertOk()
        ->assertJsonPath('data.has_manual_payout_details', false)
        ->assertJsonPath('data.manual_payout_details_masked', null)
        ->assertJsonPath('data.has_paypal_email', true);
});

/* ---------------------------------------------------- account settings */

it('lets a buyer set manual payout details on /account/settings without touching the PayPal email', function () {
    $buyer = User::factory()->create(['email' => 'rpm'.uniqid().'@example.com']);
    app(ReferralPayoutService::class)->setPaypalEmail($buyer, 'pay.me@example.com');

    $this->actingAs($buyer)->put('/account/settings/referral-payout', ['manual_payout_details' => str_repeat('x', 501)])
        ->assertSessionHasErrorsIn('payout', ['manual_payout_details']);

    $this->actingAs($buyer)->put('/account/settings/referral-payout', ['manual_payout_details' => 'MTN MoMo 677 12 34 56'])
        ->assertRedirect(route('account.settings').'#referral-payouts');

    expect(ReferralPayoutProfile::manualPayoutDetailsFor($buyer))->toBe('MTN MoMo 677 12 34 56')
        ->and(ReferralPayoutProfile::paypalEmailFor($buyer))->toBe('pay.me@example.com');

    $this->actingAs($buyer)->get('/account/settings')->assertOk()
        ->assertSee('MTN MoMo *** ** 34 56')
        ->assertDontSee('677 12 34 56')
        ->assertSee('Commissions in XAF are paid by Mobile Money or bank transfer — our finance team will contact you.');
});
