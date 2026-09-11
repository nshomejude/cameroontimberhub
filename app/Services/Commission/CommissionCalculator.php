<?php

namespace App\Services\Commission;

use App\Models\CommissionRule;
use App\Models\Order;
use DateTimeInterface;
use RuntimeException;

/**
 * Pure, deterministic marketplace-commission resolution + charge/credit
 * (billing engine M7, plan §15).
 *
 * No rate is ever hard-coded here: every number comes from a
 * `commission_rules` row. When no active rule matches, no commission line is
 * shown or charged.
 *
 * Commission is assessed on `Order.subtotal_amount` (the transaction value —
 * shipping and tax are excluded per plan §15), for the SUPPLIER side of the
 * trade: `Order.company_id` is the supplier (see `Order`/`OrderService`
 * docblocks — buyer facts are the `buyer_*` snapshot columns), so the
 * supplier's `effectivePlan()` resolves both the segment and the plan tier
 * (`Plan.slug`) the rate is looked up against.
 *
 * Domestic vs. international is the supplier's country
 * (`Company.country_code`) compared against the order's destination — the
 * order's `destination_country_code` when set (the real shipping
 * destination), else the buyer's snapshot country
 * (`Order.buyer_country_code`) as the best available proxy.
 *
 * ── Money representation ────────────────────────────────────────────────
 * Same convention as `App\Services\Tax\TaxCalculator`: bcmath decimal
 * strings only, no floats. The commission amount is first computed at 8dp
 * precision, capped, then rounded half-up to the currency's real precision
 * (XAF = 0dp, everything else = 2dp) and re-expressed at 2dp so it drops
 * straight into `orders.commission_amount`.
 */
class CommissionCalculator
{
    /**
     * The most specific active rule for $segment / $planTier at $at, or null
     * when none applies (→ no commission).
     *
     * Specificity, most-wins:
     *   1. exact plan_tier match beats a rule with plan_tier = null (all tiers);
     *   2. exact segment match beats a rule with segment = null (all segments);
     *   3. later effective_from beats earlier (newest rule in force wins).
     */
    public function for(string $segment, ?string $planTier = null, ?DateTimeInterface $at = null): ?CommissionRule
    {
        $candidates = CommissionRule::query()
            ->active($at)
            ->where(fn ($q) => $q->whereNull('segment')->orWhere('segment', $segment))
            ->where(fn ($q) => $q->whereNull('plan_tier')->when(
                $planTier !== null,
                fn ($q2) => $q2->orWhere('plan_tier', $planTier)
            ))
            ->get();

        return $candidates
            ->sortByDesc(fn (CommissionRule $r) => [
                ($planTier !== null && $r->plan_tier === $planTier) ? 1 : 0,
                $r->segment === $segment ? 1 : 0,
                optional($r->effective_from)->timestamp ?? 0,
                $r->id,
            ])
            ->first();
    }

    /**
     * @return array{rate: ?string, amount: string, rule_id: ?int, is_international: bool}
     */
    public function calculate(Order $order): array
    {
        $order->loadMissing('company');
        $supplier = $order->company;

        $destination = $order->destination_country_code ?? $order->buyer_country_code ?? null;

        return $this->preview(
            supplierCountry: $supplier?->country_code,
            destinationCountry: $destination,
            segment: $supplier?->effectivePlan()?->segment,
            planTier: $supplier?->effectivePlan()?->slug,
            subtotal: (string) $order->subtotal_amount,
            currency: (string) $order->currency->value,
        );
    }

    /**
     * Same resolution as `calculate()`, but usable BEFORE an `Order` exists —
     * the pre-commit disclosure on a quote's accept screen (plan §15: "shown
     * before the order is committed"). Takes the supplier/destination
     * countries, segment/tier and money facts directly rather than reading
     * them off an `Order` row.
     *
     * @return array{rate: ?string, amount: string, rule_id: ?int, is_international: bool}
     */
    public function preview(
        ?string $supplierCountry,
        ?string $destinationCountry,
        ?string $segment,
        ?string $planTier,
        float|string $subtotal,
        string $currency,
    ): array {
        $isInternational = $this->countriesDiffer($supplierCountry, $destinationCountry);

        if ($segment === null) {
            return ['rate' => null, 'amount' => $this->scale2('0', $currency), 'rule_id' => null, 'is_international' => $isInternational];
        }

        $rule = $this->for($segment, $planTier);

        if ($rule === null) {
            return ['rate' => null, 'amount' => $this->scale2('0', $currency), 'rule_id' => null, 'is_international' => $isInternational];
        }

        $rate = $isInternational ? (string) $rule->international_rate : (string) $rule->domestic_rate;
        $subtotal = $this->scale2((string) $subtotal, $currency);

        $raw = bcmul($subtotal, $rate, 8);

        if ($rule->cap_percent !== null) {
            $percentCap = bcmul($subtotal, (string) $rule->cap_percent, 8);
            $raw = bccomp($raw, $percentCap, 8) > 0 ? $percentCap : $raw;
        }

        if ($rule->cap_amount !== null) {
            $amountCap = bcadd((string) $rule->cap_amount, '0', 8);
            $raw = bccomp($raw, $amountCap, 8) > 0 ? $amountCap : $raw;
        }

        $amount = $this->bcRoundHalfUp($raw, $this->precisionFor($currency));
        $amount = $this->scale2($amount, $currency);

        return [
            'rate' => $rate,
            'amount' => $amount,
            'rule_id' => $rule->id,
            'is_international' => $isInternational,
        ];
    }

    /**
     * Snapshot the commission on the order (idempotent). No-op when already
     * charged, when the order is not a protected trade (a
     * `TradeAssuranceAgreement` exists — plan §15: commission applies to
     * protected-trade orders only), or when there is no active rule for the
     * supplier's segment — cancel-before-charge naturally means no
     * commission, since this is the only place `is_commission_charged` is
     * ever set true.
     */
    public function charge(Order $order): void
    {
        if ($order->is_commission_charged) {
            return;
        }

        if (! $order->tradeAssuranceAgreement()->exists()) {
            return;
        }

        $result = $this->calculate($order);

        if ($result['rule_id'] === null) {
            return;
        }

        $order->forceFill([
            'commission_rate' => $result['rate'],
            'commission_amount' => $result['amount'],
            'commission_rule_id' => $result['rule_id'],
            'is_commission_charged' => true,
        ])->save();
    }

    /**
     * Credit back part of an already-charged commission (refund / dispute
     * resolution). Guards against crediting more than was actually charged.
     */
    public function credit(Order $order, float|string $amount, string $reason): void
    {
        $amount = $this->scale2((string) $amount, (string) $order->currency->value);
        $charged = $this->scale2((string) ($order->commission_amount ?? '0'), (string) $order->currency->value);
        $alreadyCredited = $this->scale2((string) ($order->commission_credited_amount ?? '0'), (string) $order->currency->value);
        $room = bcsub($charged, $alreadyCredited, 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('A commission credit must be a positive amount.');
        }

        if (bccomp($amount, $room, 2) > 0) {
            throw new RuntimeException('A commission credit cannot exceed the remaining charged amount.');
        }

        $order->forceFill([
            'commission_credited_amount' => bcadd($alreadyCredited, $amount, 2),
        ])->save();

        activity('commission_credit')
            ->performedOn($order)
            ->causedBy(auth()->user())
            ->event('credited')
            ->withProperties(['amount' => $amount, 'reason' => $reason])
            ->log('Commission credited: '.$reason);
    }

    private function countriesDiffer(?string $supplierCountry, ?string $destinationCountry): bool
    {
        $supplierCountry = $supplierCountry ? strtoupper(trim($supplierCountry)) : null;
        $destinationCountry = $destinationCountry ? strtoupper(trim($destinationCountry)) : null;

        if ($supplierCountry === null || $destinationCountry === null || $supplierCountry === '' || $destinationCountry === '') {
            return false;
        }

        return $supplierCountry !== $destinationCountry;
    }

    private function precisionFor(string $currency): int
    {
        return in_array($currency, ['XAF', 'XOF'], true) ? 0 : 2;
    }

    private function scale2(string $n, string $currency): string
    {
        return bcadd($n, '0', 2);
    }

    /** Half-up rounding for bcmath decimal strings (no float involved). */
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
