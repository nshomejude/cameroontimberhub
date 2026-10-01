<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the PRICING_SPEC §15 marketplace-commission rates (catalogue codes
 * TX-DOM-* / TX-INT-*) as `commission_rules` rows.
 *
 * Spec tier → real plan slug mapping (the spec's tier names predate the
 * plans actually seeded by `PlanSeeder`):
 *
 *   §15 "Free / unlisted"        → sell/`free`  (3.0% dom, 5.0% intl, cap 5%)
 *                                  — a supplier with NO plan at all is
 *                                  resolved as sell/`free` by
 *                                  `CommissionCalculator`, so no wildcard
 *                                  (plan_tier = null) rule is needed; a
 *                                  wildcard would also catch Enterprise.
 *   §15 "Starter / Professional" → sell/`professional`,
 *                                  export/`exporter-professional`
 *                                  (2.5% dom, 4.0% intl, cap 4%)
 *   §15 "Pro / Business"         → export/`exporter-business`
 *                                  (2.0% dom, 3.0% intl, cap 3%) — the sell
 *                                  segment has no business-tier plan.
 *   §15 "Enterprise"             → negotiated: NO rule seeded (`enterprise`,
 *                                  `exporter-enterprise` carry 0% until finance
 *                                  adds the contract rate in /admin).
 *
 * Every rule also carries the §15 fixed cap of $5,000 (`cap_currency` USD —
 * `CommissionCalculator` converts it into the order's currency).
 *
 * Rates are stored as FRACTIONS (0.03 = 3%), matching the
 * `commission_rules.domestic_rate` decimal(6,4) column and the admin form.
 *
 * Idempotent and non-destructive: a rule is inserted only when NO rule (of any
 * state or date) exists for that segment + plan tier, and only when the plan
 * it targets exists. Admin edits are never overwritten. Shared with the data
 * migration `2026_10_01_170010_seed_default_commission_rules`.
 */
class CommissionRuleSeeder extends Seeder
{
    /**
     * @return list<array{name: string, segment: string, plan_tier: string, domestic_rate: string, international_rate: string, cap_amount: string, cap_currency: string, cap_percent: string, notes: string}>
     */
    public static function defaults(): array
    {
        $rule = fn (string $name, string $segment, string $tier, string $domestic, string $international, string $capPercent, string $codes): array => [
            'name' => $name,
            'segment' => $segment,
            'plan_tier' => $tier,
            'domestic_rate' => $domestic,
            'international_rate' => $international,
            'cap_amount' => '5000.00',
            'cap_currency' => 'USD',
            'cap_percent' => $capPercent,
            'notes' => "PRICING_SPEC §15 ({$codes}). Seeded default — to change the rate, add a new rule with a later "
                .'effective_from rather than editing this one (see RUNBOOK → Marketplace commission).',
        ];

        return [
            $rule('Free / unlisted supplier (§15)', 'sell', 'free', '0.0300', '0.0500', '0.0500', 'TX-DOM-FREE, TX-INT-FREE'),
            $rule('Professional supplier (§15 Starter / Professional)', 'sell', 'professional', '0.0250', '0.0400', '0.0400', 'TX-DOM-START, TX-INT-PRO'),
            $rule('Exporter Professional (§15 Starter / Professional)', 'export', 'exporter-professional', '0.0250', '0.0400', '0.0400', 'TX-DOM-START, TX-INT-PRO'),
            $rule('Exporter Business (§15 Pro / Business)', 'export', 'exporter-business', '0.0200', '0.0300', '0.0300', 'TX-DOM-PRO, TX-INT-BIZ'),
        ];
    }

    public function run(): void
    {
        self::insertMissing();
    }

    /** Insert every default whose segment/tier has no rule yet. Returns how many were created. */
    public static function insertMissing(): int
    {
        $created = 0;

        foreach (self::defaults() as $default) {
            $planExists = Plan::query()
                ->where('segment', $default['segment'])
                ->where('slug', $default['plan_tier'])
                ->exists();

            $ruleExists = CommissionRule::query()
                ->where('segment', $default['segment'])
                ->where('plan_tier', $default['plan_tier'])
                ->exists();

            if (! $planExists || $ruleExists) {
                continue;
            }

            CommissionRule::create($default + [
                'is_active' => true,
                'effective_from' => null,
                'effective_until' => null,
            ]);

            $created++;
        }

        return $created;
    }
}
