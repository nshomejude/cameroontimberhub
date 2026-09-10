<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxRule;
use App\Services\Tax\TaxCalculator;

function calc(): TaxCalculator
{
    return app(TaxCalculator::class);
}

it('returns zero tax and a null rule when no active rule matches', function () {
    $b = calc()->breakdown('500000.00', 'XAF', 'CM', 'sell');

    expect($b['rule_id'])->toBeNull()
        ->and($b['tax_label'])->toBeNull()
        ->and((float) $b['tax_amount'])->toBe(0.0)
        ->and($b['total'])->toBe($b['subtotal'])
        ->and((float) $b['total'])->toBe(500000.0);
});

it('ignores an inactive rule (Cameroon TVA ships off)', function () {
    TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => false]);

    expect(calc()->breakdown('500000.00', 'XAF', 'CM')['rule_id'])->toBeNull();
});

it('applies an active CM rule with exact integer XAF math (no float drift)', function () {
    $rule = TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => true]);

    $b = calc()->breakdown('500000.00', 'XAF', 'CM', 'sell');

    expect($b['rule_id'])->toBe($rule->id)
        ->and($b['tax_label'])->toBe('Cameroon TVA')
        ->and($b['tax_rate'])->toBe('0.1925')
        ->and($b['tax_amount'])->toBe('96250.00')
        ->and($b['total'])->toBe('596250.00');
});

it('rounds tax half-up to 2 dp for a USD rule producing a fraction', function () {
    TaxRule::create(['name' => 'US Sales Tax', 'jurisdiction' => 'US', 'rate' => 0.0825, 'is_active' => true]);

    // 99.99 * 0.0825 = 8.249175 -> 8.25
    $b = calc()->breakdown('99.99', 'USD', 'US');

    expect($b['tax_amount'])->toBe('8.25')
        ->and($b['total'])->toBe('108.24');
});

it('prefers an exact jurisdiction match over the * rest-of-world default', function () {
    TaxRule::create(['name' => 'World default', 'jurisdiction' => '*', 'rate' => 0.05, 'is_active' => true]);
    $cm = TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => true]);

    expect(calc()->for('CM')->id)->toBe($cm->id)
        ->and(calc()->for('GB')->name)->toBe('World default');
});

it('prefers a segment-specific rule over an applies-to-all rule', function () {
    TaxRule::create(['name' => 'All', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => true, 'applies_to' => null]);
    $seg = TaxRule::create(['name' => 'Export zero-rate', 'jurisdiction' => 'CM', 'rate' => 0.0, 'is_active' => true, 'applies_to' => 'export']);

    expect(calc()->for('CM', 'export')->id)->toBe($seg->id)
        ->and(calc()->for('CM', 'sell')->name)->toBe('All');
});

it('resolves the rule in force at a given date, not a future-dated one', function () {
    $old = TaxRule::create(['name' => 'Old', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => true, 'effective_from' => '2020-01-01', 'effective_until' => '2026-06-30']);
    $new = TaxRule::create(['name' => 'New', 'jurisdiction' => 'CM', 'rate' => 0.20, 'is_active' => true, 'effective_from' => '2026-07-01']);

    expect(calc()->for('CM', null, new DateTime('2026-03-15'))->id)->toBe($old->id)
        ->and(calc()->for('CM', null, new DateTime('2026-09-01'))->id)->toBe($new->id);
});

it('forbids editing the rate of an active rule and supports superseding it with a new effective-from', function () {
    $old = TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => true, 'effective_from' => '2024-01-01']);

    expect(fn () => $old->update(['rate' => 0.20]))->toThrow(RuntimeException::class);

    // Non-rate edits are fine.
    $old->refresh();
    $old->update(['effective_until' => '2026-06-30', 'notes' => 'superseded']);

    $new = TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => 0.20, 'is_active' => true, 'effective_from' => '2026-07-01']);

    // The old rule still resolves for dates before the switch.
    expect(calc()->for('CM', null, new DateTime('2025-01-01'))->id)->toBe($old->id)
        ->and(calc()->for('CM', null, new DateTime('2026-08-01'))->id)->toBe($new->id);
});

it('does not retroactively change a subscription price snapshot when a rule changes', function () {
    (new Database\Seeders\PlanSeeder)->run();
    $plan = Plan::where('slug', 'professional')->first();

    $company = \App\Models\Company::factory()->create();
    $company->subscriptions()->delete();

    $sub = Subscription::create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'status' => \App\Enums\SubscriptionStatus::Active->value,
        'price_amount' => '50000.00',
        'price_currency' => 'XAF',
    ]);

    TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => 0.1925, 'is_active' => true]);

    expect($sub->fresh()->price_amount)->toBe('50000.00');
});
