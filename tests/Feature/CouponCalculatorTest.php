<?php

use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Services\Billing\CouponCalculator;

function makeCoupon(array $overrides = []): Coupon
{
    return Coupon::create(array_merge([
        'code' => 'launch20',
        'type' => 'percent',
        'value' => '0.2000',
        'currency' => null,
        'is_active' => true,
        'max_redemptions_per_company' => 1,
    ], $overrides));
}

it('resolves an active coupon case-insensitively', function () {
    makeCoupon(['code' => 'LAUNCH20']);

    $calculator = app(CouponCalculator::class);

    expect($calculator->resolve('launch20')?->code)->toBe('LAUNCH20')
        ->and($calculator->resolve('LaUnCh20')?->code)->toBe('LAUNCH20');
});

it('returns null for an inactive, expired, or unknown code', function () {
    makeCoupon(['code' => 'OFF', 'is_active' => false]);
    makeCoupon(['code' => 'EXPIRED', 'valid_until' => now()->subDay()]);

    $calculator = app(CouponCalculator::class);

    expect($calculator->resolve('OFF'))->toBeNull()
        ->and($calculator->resolve('EXPIRED'))->toBeNull()
        ->and($calculator->resolve('DOES-NOT-EXIST'))->toBeNull();
});

it('eligibleFor respects segment filter, plan filter, and per-company redemption cap', function () {
    $sellPlan = Plan::factory()->create(['segment' => 'sell']);
    $buyPlan = Plan::factory()->create(['segment' => 'buy']);
    $company = Company::factory()->create();

    $coupon = makeCoupon(['applies_to_segments' => ['sell']]);
    $calculator = app(CouponCalculator::class);

    expect($calculator->eligibleFor($coupon, $company, $sellPlan))->toBeTrue()
        ->and($calculator->eligibleFor($coupon, $company, $buyPlan))->toBeFalse();

    $planCoupon = makeCoupon(['code' => 'PLANONLY', 'applies_to_plan_ids' => [$sellPlan->id]]);
    expect($calculator->eligibleFor($planCoupon, $company, $sellPlan))->toBeTrue()
        ->and($calculator->eligibleFor($planCoupon, $company, $buyPlan))->toBeFalse();

    $cappedCoupon = makeCoupon(['code' => 'CAPPED', 'max_redemptions_per_company' => 1]);
    CouponRedemption::create([
        'coupon_id' => $cappedCoupon->id,
        'company_id' => $company->id,
        'amount_discounted' => '100.00',
        'currency' => 'XAF',
        'redeemed_at' => now(),
    ]);

    expect($calculator->eligibleFor($cappedCoupon, $company, $sellPlan))->toBeFalse();
});

it('computes a percent discount on XAF 500000 at 0.20 to 100000', function () {
    $coupon = makeCoupon(['type' => 'percent', 'value' => '0.2000']);

    expect(app(CouponCalculator::class)->discount($coupon, '500000.00', 'XAF'))->toBe('100000.00');
});

it('caps a fixed discount at the subtotal when the fixed value exceeds it', function () {
    $coupon = makeCoupon(['code' => 'FIXED10K', 'type' => 'fixed', 'value' => '10000.0000', 'currency' => 'XAF']);

    expect(app(CouponCalculator::class)->discount($coupon, '5000.00', 'XAF'))->toBe('5000.00');
});

it('returns zero discount for a fixed coupon when currencies mismatch', function () {
    $coupon = makeCoupon(['code' => 'USDFIXED', 'type' => 'fixed', 'value' => '10.0000', 'currency' => 'USD']);

    expect(app(CouponCalculator::class)->discount($coupon, '500000.00', 'XAF'))->toBe('0.00');
});

it('increments redemptions_count and records a redemption row', function () {
    $coupon = makeCoupon(['code' => 'ONEUSE', 'max_redemptions' => 5]);
    $company = Company::factory()->create();

    $redemption = app(CouponCalculator::class)->redeem($coupon, $company, '100000.00', 'XAF');

    expect($redemption)->toBeInstanceOf(CouponRedemption::class)
        ->and($coupon->fresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::count())->toBe(1);
});

it('throws when a redemption would exceed max_redemptions', function () {
    $coupon = makeCoupon(['code' => 'ONLYONE', 'max_redemptions' => 1, 'max_redemptions_per_company' => null]);
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app(CouponCalculator::class)->redeem($coupon, $companyA, '100.00', 'XAF');

    app(CouponCalculator::class)->redeem($coupon, $companyB, '100.00', 'XAF');
})->throws(RuntimeException::class);

it('never rewrites a past redemption amount when the coupon value later changes', function () {
    $coupon = makeCoupon(['code' => 'SNAPSHOT', 'type' => 'percent', 'value' => '0.1000']);
    $company = Company::factory()->create();

    $discount = app(CouponCalculator::class)->discount($coupon, '100000.00', 'XAF');
    $redemption = app(CouponCalculator::class)->redeem($coupon, $company, $discount, 'XAF');

    $coupon->update(['value' => '0.5000']);

    expect((string) $redemption->fresh()->amount_discounted)->toBe('10000.00');
});
