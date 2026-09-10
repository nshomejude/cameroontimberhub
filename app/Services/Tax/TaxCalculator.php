<?php

namespace App\Services\Tax;

use App\Models\TaxRule;
use DateTimeInterface;

/**
 * Pure, deterministic tax resolution + breakdown (billing engine M5).
 *
 * No rate is ever hard-coded here: every number comes from a `tax_rules`
 * row. When no active rule matches, tax is 0% and the total equals the
 * subtotal (no zero-tax line is surfaced).
 *
 * ── Money representation ──────────────────────────────────────────────
 * `Plan.price_amount`, `Payment.amount` and `Subscription.price_amount` are
 * all stored `decimal(14,2)` (major units, 2 dp — e.g. XAF 500000.00).
 * This service works on those decimal strings with bcmath (never floats)
 * and returns decimal strings scaled to 2 dp, so results drop straight
 * into `Payment.amount` with no drift. The *tax* component is first
 * rounded half-up to the currency's real precision (XAF = 0 dp, everything
 * else = 2 dp) before being re-expressed at 2 dp.
 */
class TaxCalculator
{
    /**
     * The most specific active rule for $jurisdiction / $segment at $at, or
     * null when none applies (→ 0% tax).
     *
     * Specificity, most-wins:
     *   1. exact jurisdiction match beats the "*" rest-of-world default;
     *   2. a rule whose `applies_to` equals $segment beats one with
     *      `applies_to = null` (applies-to-all);
     *   3. later `effective_from` beats earlier (newest rule in force wins).
     */
    public function for(string $jurisdiction, ?string $segment = null, ?DateTimeInterface $at = null): ?TaxRule
    {
        $jurisdiction = strtoupper($jurisdiction);

        $candidates = TaxRule::query()
            ->active($at)
            ->where(fn ($q) => $q->whereIn('jurisdiction', [$jurisdiction, '*']))
            ->where(fn ($q) => $q->whereNull('applies_to')->when($segment !== null, fn ($q2) => $q2->orWhere('applies_to', $segment)))
            ->get();

        return $candidates
            ->sortByDesc(fn (TaxRule $r) => [
                $r->jurisdiction === $jurisdiction ? 1 : 0,
                ($segment !== null && $r->applies_to === $segment) ? 1 : 0,
                optional($r->effective_from)->timestamp ?? 0,
                $r->id,
            ])
            ->first();
    }

    /**
     * @return array{subtotal:string,tax_rate:string,tax_label:?string,tax_amount:string,total:string,rule_id:?int}
     */
    public function breakdown(int|float|string $subtotal, string $currency, string $jurisdiction, ?string $segment = null): array
    {
        $currency = strtoupper($currency);
        $subtotal = $this->scale2((string) $subtotal);
        $rule = $this->for($jurisdiction, $segment);

        if ($rule === null) {
            return [
                'subtotal' => $subtotal,
                'tax_rate' => '0.0000',
                'tax_label' => null,
                'tax_amount' => $this->scale2('0'),
                'total' => $subtotal,
                'rule_id' => null,
            ];
        }

        $rate = (string) $rule->rate;                       // e.g. "0.1925"
        $raw = bcmul($subtotal, $rate, 8);
        $tax = $this->bcRoundHalfUp($raw, $this->precisionFor($currency));
        $tax = $this->scale2($tax);
        $total = bcadd($subtotal, $tax, 2);

        return [
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_label' => $rule->name,
            'tax_amount' => $tax,
            'total' => $total,
            'rule_id' => $rule->id,
        ];
    }

    private function precisionFor(string $currency): int
    {
        // Zero-decimal currencies. XAF (Central African CFA franc) has no
        // minor unit; every other currency we accept (USD/EUR/GBP/CNY) has 2.
        return in_array($currency, ['XAF', 'XOF'], true) ? 0 : 2;
    }

    private function scale2(string $n): string
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
