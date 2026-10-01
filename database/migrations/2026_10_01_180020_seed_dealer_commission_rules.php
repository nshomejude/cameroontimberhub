<?php

use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration (owner approved 2026-10-01): give an EXISTING database the
 * dealer-segment marketplace-commission rules —
 *   deal/dealer-free    = Free rates          (3% dom / 5% intl, cap 5%, $5,000)
 *   deal/dealer-pro     = Professional rates  (2.5% / 4%, cap 4%)
 *   deal/dealer-network = Business rates      (2% / 3%, cap 3%)
 *
 * Same insert-if-missing path as `CommissionRuleSeeder` (and the earlier
 * `2026_10_01_170010_seed_default_commission_rules`): a rule is created only
 * when no rule exists for that segment/plan tier and the plan exists, so an
 * admin-created rule is never touched. `down()` is a deliberate no-op — an
 * active rule may already be snapshotted onto orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        CommissionRuleSeeder::insertMissing();
    }

    public function down(): void
    {
        //
    }
};
