<?php

use App\Filament\Resources\TaxRules\Pages\CreateTaxRule;
use App\Models\Company;
use App\Models\Plan;
use App\Models\TaxRule;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TaxRuleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('hides the Tax Rules resource from staff without pricing.manage', function () {
    $this->actingAs(staff('content_manager'));

    $this->get('/admin/tax-rules')->assertForbidden();
});

it('shows the Tax Rules resource to a finance officer (pricing.manage)', function () {
    $this->actingAs(staff('finance_officer'));

    $this->get('/admin/tax-rules')->assertOk();
});

it('creates a tax rule, storing the rate as a fraction and stamping created_by', function () {
    $user = staff('finance_officer');
    $this->actingAs($user);

    Livewire::test(CreateTaxRule::class)
        ->fillForm([
            'name' => 'Cameroon TVA',
            'jurisdiction' => 'cm',
            'rate' => 19.25,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = TaxRule::firstWhere('name', 'Cameroon TVA');

    expect($rule)->not->toBeNull()
        ->and($rule->jurisdiction)->toBe('CM')
        ->and((string) $rule->rate)->toBe('0.1925')
        ->and($rule->created_by)->toBe($user->id);
});

it('records the FoundationTest permission counts including pricing.manage', function () {
    expect(\Spatie\Permission\Models\Permission::whereName('pricing.manage')->exists())->toBeTrue();
});

it('shows the checkout TVA line only when a rule is active', function () {
    (new PlanSeeder)->run();
    (new TaxRuleSeeder)->run(); // seeds Cameroon TVA INACTIVE

    $plan = Plan::where('slug', 'professional')->first();
    $company = Company::factory()->create(['country_code' => 'CM']);
    $member = User::factory()->create();
    $company->users()->attach($member, ['role' => 'owner']);

    // Inactive rule -> no tax line.
    $this->actingAs($member)->get(route('billing.checkout', $plan))
        ->assertOk()
        ->assertDontSee('19.25%');

    // Activate it -> line appears.
    TaxRule::where('jurisdiction', 'CM')->update(['is_active' => true]);

    $this->actingAs($member)->get(route('billing.checkout', $plan))
        ->assertOk()
        ->assertSee('19.25%');
});
