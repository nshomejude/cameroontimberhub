<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M7 (plan §15) — commission snapshot columns on `orders`.
 *
 * Additive/nullable, matching the rest of the order's money columns:
 * once `is_commission_charged = true`, `commission_rate` / `commission_amount`
 * / `commission_rule_id` are frozen (see App\Observers\OrderCommissionObserver)
 * so a later `commission_rules` edit never re-rates a past order.
 * `commission_credited_amount` is a running total credited back via
 * refund/dispute resolution, so a second credit cannot exceed what was
 * actually charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('commission_rate', 6, 4)->nullable()->after('total_amount');
            $table->decimal('commission_amount', 14, 2)->nullable()->after('commission_rate');
            $table->foreignId('commission_rule_id')->nullable()->after('commission_amount')
                ->constrained('commission_rules')->nullOnDelete();
            $table->boolean('is_commission_charged')->default(false)->after('commission_rule_id');
            $table->decimal('commission_credited_amount', 14, 2)->default(0)->after('is_commission_charged');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_rule_id');
            $table->dropColumn(['commission_rate', 'commission_amount', 'is_commission_charged', 'commission_credited_amount']);
        });
    }
};
