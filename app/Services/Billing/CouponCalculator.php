<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pure coupon resolution/eligibility/discount calculation (billing engine
 * M8, plan §19). `apply`-style methods here have no side effects; only
 * `redeem()` writes anything.
 *
 * ── Scope note ───────────────────────────────────────────────────────
 * This service is deliberately NOT wired into
 * App\Http\Controllers\Public\PaymentCheckoutController /
 * BillingCheckoutController yet — those files are owned by a concurrent
 * work stream (Phase 3 M6) in this shared worktree. M8 ships the data
 * model + admin CRUD + this pure calculator; checkout integration
 * (resolving a coupon code at checkout, calling redeem() after payment
 * success, subtracting the discount from the subtotal before tax) is a
 * documented follow-up task for whoever next touches the checkout flow.
 * A coupon must NEVER reduce mandatory tax (plan §19) — the intended
 * integration point is: discount the subtotal, THEN run TaxCalculator on
 * the discounted subtotal, never the other way around.
 *
 * ── Money representation ─────────────────────────────────────────────
 * Mirrors App\Services\Tax\TaxCalculator: bcmath decimal strings only, no
 * floats. Amounts are rounded half-up to the currency's real precision
 * (XAF = 0 dp, everything else = 2 dp) then re-expressed at 2 dp so they
 * drop straight into Payment.amount / Invoice columns.
 */
class CouponCalculator
{
    /** Case-insensitive lookup of an ACTIVE coupon by code, or null. */
    public function resolve(string $code): ?Coupon
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->active()
            ->first();
    }

    /**
     * Whether $company may redeem $coupon against $plan right now: the
     * coupon is active, its segment/plan filters allow $plan, and $company
     * has not exhausted its per-company redemption cap.
     */
    public function eligibleFor(Coupon $coupon, Company $company, Plan $plan): bool
    {
        if (! Coupon::query()->whereKey($coupon->id)->active()->exists()) {
            return false;
        }

        if (! $coupon->appliesTo($plan)) {
            return false;
        }

        if ($coupon->max_redemptions_per_company !== null) {
            $used = $coupon->redemptions()->where('company_id', $company->id)->count();

            if ($used >= $coupon->max_redemptions_per_company) {
                return false;
            }
        }

        return true;
    }

    /**
     * The discount amount for $coupon against a $subtotalAmount in
     * $currency. Never negative, never exceeds the subtotal.
     *
     * - `percent`: bcmul(subtotal, value) rounded to the currency's
     *   precision. `$currency` is only used for rounding precision — a
     *   percent coupon has no currency of its own.
     * - `fixed`: min(coupon.value, subtotal), but ONLY when the coupon's
     *   own `currency` matches `$currency`. A currency mismatch on a fixed
     *   coupon returns '0.00' (documented decision, see class docblock) —
     *   a fixed-amount coupon denominated in USD should not silently
     *   apply its face value against an XAF subtotal (or vice versa); the
     *   caller is expected to have already filtered coupons by currency
     *   via `eligibleFor`/plan currency, this is a defensive last resort.
     */
    public function discount(Coupon $coupon, string $subtotalAmount, string $currency): string
    {
        $currency = strtoupper($currency);
        $subtotal = $this->scale2($subtotalAmount);

        if ($coupon->isPercent()) {
            $raw = bcmul($subtotal, (string) $coupon->value, 8);
            $discount = $this->bcRoundHalfUp($raw, $this->precisionFor($currency));

            return $this->cap($this->scale2($discount), $subtotal);
        }

        // fixed
        if ($coupon->currency === null || strtoupper($coupon->currency) !== $currency) {
            return $this->scale2('0');
        }

        return $this->cap($this->scale2((string) $coupon->value), $subtotal);
    }

    /**
     * Record a redemption and increment the coupon's counter, guarding
     * against a race that would exceed `max_redemptions`: the coupon row
     * is locked (`lockForUpdate`) inside a transaction and the cap is
     * re-checked under that lock before writing, so two concurrent
     * requests against a coupon with one redemption left can never both
     * succeed.
     */
    public function redeem(Coupon $coupon, Company $company, string $amountDiscounted, string $currency, ?int $paymentId = null): CouponRedemption
    {
        return DB::transaction(function () use ($coupon, $company, $amountDiscounted, $currency, $paymentId) {
            /** @var Coupon $locked */
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();

            if ($locked->max_redemptions !== null && $locked->redemptions_count >= $locked->max_redemptions) {
                throw new RuntimeException("Coupon [{$locked->code}] has reached its maximum redemptions.");
            }

            $redemption = CouponRedemption::create([
                'coupon_id' => $locked->id,
                'company_id' => $company->id,
                'payment_id' => $paymentId,
                'amount_discounted' => $this->scale2($amountDiscounted),
                'currency' => strtoupper($currency),
                'redeemed_at' => now(),
            ]);

            $locked->increment('redemptions_count');

            activity('coupon')
                ->performedOn($locked)
                ->event('redeemed')
                ->withProperties([
                    'coupon_code' => $locked->code,
                    'company_id' => $company->id,
                    'amount_discounted' => $redemption->amount_discounted,
                    'currency' => $redemption->currency,
                ])
                ->log("Coupon {$locked->code} redeemed");

            return $redemption;
        });
    }

    private function cap(string $discount, string $subtotal): string
    {
        if (bccomp($discount, $subtotal, 2) === 1) {
            return $subtotal;
        }

        if (bccomp($discount, '0', 2) === -1) {
            return $this->scale2('0');
        }

        return $discount;
    }

    private function precisionFor(string $currency): int
    {
        return in_array($currency, ['XAF', 'XOF'], true) ? 0 : 2;
    }

    private function scale2(string $n): string
    {
        return bcadd($n, '0', 2);
    }

    /** Half-up rounding for bcmath decimal strings (no float involved). Mirrors TaxCalculator. */
    private function bcRoundHalfUp(string $number, int $precision): string
    {
        if (! str_contains($number, '.')) {
            return $number;
        }

        [$int, $frac] = explode('.', $number, 2);

        if (strlen($frac) <= $precision) {
            return $number;
        }

        $kept = $int.($precision > 0 ? '.'.substr($frac, 0, $precision) : '');
        $nextDigit = $frac[$precision];

        if ($nextDigit >= '5') {
            $increment = $precision > 0
                ? '0.'.str_repeat('0', $precision - 1).'1'
                : '1';
            $kept = bcadd($kept, $increment, $precision);
        }

        return $kept;
    }
}
