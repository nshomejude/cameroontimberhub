<?php

use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: give an EXISTING production database the PRICING_SPEC §15
 * marketplace-commission rules (before this, `commission_rules` was empty, so
 * every order carried 0% commission).
 *
 * Insert-if-missing only, via the same code path as `CommissionRuleSeeder`
 * (which `ReferenceDataSeeder` runs on fresh installs): a rule is created only
 * when no rule at all exists for its segment/plan tier AND the plan it targets
 * exists. An admin-created or admin-edited rule is never touched, and a fresh
 * database (no plans yet) is left alone for the seeder to fill.
 *
 * `down()` is a deliberate no-op: once a rule is active it may already have
 * been snapshotted onto orders (`orders.commission_rule_id`), so it must not
 * be deleted by a rollback — deactivate it in /admin → Commission rules.
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
