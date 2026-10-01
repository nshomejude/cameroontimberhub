<?php

use App\Filament\Resources\CommissionRules\Pages\CreateCommissionRule;
use App\Models\CommissionRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('hides the Commission Rules resource from staff without pricing.manage', function () {
    $this->actingAs(staff('content_manager'));

    $this->get('/admin/commission-rules')->assertForbidden();
});

it('shows the Commission Rules resource to a finance officer (pricing.manage)', function () {
    $this->actingAs(staff('finance_officer'));

    $this->get('/admin/commission-rules')->assertOk();
});

it('creates a commission rule, storing rates as fractions and stamping created_by', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('pricing.manage');
    $this->actingAs($user);

    Livewire::test(CreateCommissionRule::class)
        ->fillForm([
            'name' => 'Sell tier default',
            'segment' => 'sell',
            'domestic_rate' => 3.0,
            'international_rate' => 5.0,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = CommissionRule::firstWhere('name', 'Sell tier default');

    expect($rule)->not->toBeNull()
        ->and($rule->segment)->toBe('sell')
        ->and((string) $rule->domestic_rate)->toBe('0.0300')
        ->and((string) $rule->international_rate)->toBe('0.0500')
        ->and($rule->created_by)->toBe($user->id);
});

it('stores a fixed cap with its currency (the §15 $5,000 cap is USD)', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('pricing.manage');
    $this->actingAs($user);

    Livewire::test(CreateCommissionRule::class)
        ->fillForm([
            'name' => 'Capped tier',
            'segment' => 'sell',
            'plan_tier' => 'free',
            'domestic_rate' => 3.0,
            'international_rate' => 5.0,
            'cap_amount' => 5000,
            'cap_currency' => 'USD',
            'cap_percent' => 5.0,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = CommissionRule::firstWhere('name', 'Capped tier');

    expect($rule->cap_amount)->toBe('5000.00')
        ->and($rule->cap_currency)->toBe('USD')
        ->and($rule->cap_percent)->toBe('0.0500');
});

it('blocks editing the cap currency of an active rule in place', function () {
    $rule = CommissionRule::create([
        'name' => 'Active', 'segment' => 'sell', 'domestic_rate' => 0.03, 'international_rate' => 0.05,
        'cap_amount' => 5000, 'cap_currency' => 'USD', 'is_active' => true,
    ]);

    expect(fn () => $rule->update(['cap_currency' => 'XAF']))->toThrow(RuntimeException::class);
});
