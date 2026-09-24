<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referral programme: every user/company carries a unique share code
 * (CTH-XXXXXX, generated on demand), a new account records who referred it,
 * and a referrer earns a one-time commission on the referred company's FIRST
 * completed subscription payment (App\Services\Referrals\ReferralService).
 * Money follows the billing engine: decimal(14,2) + ISO currency char(3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('referred_at')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_by_company_id')->nullable()->constrained('companies')->nullOnDelete();
        });

        Schema::create('referral_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->boolean('enabled')->default(true);
            $table->decimal('rate_percent', 5, 2)->default(10);
            $table->string('basis', 20)->default('subscription');
            $table->boolean('one_time')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('referral_earnings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referrer_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('referred_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->string('source_reference', 64);
            $table->string('basis', 20)->default('subscription');
            $table->decimal('base_amount', 14, 2);
            $table->decimal('rate_percent', 5, 2);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['referrer_user_id', 'status']);
            $table->index('referred_company_id');
        });

        DB::statement("ALTER TABLE referral_earnings ADD CONSTRAINT referral_earnings_status_check CHECK (status IN ('pending','approved','paid','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_earnings');
        Schema::dropIfExists('referral_settings');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_company_id');
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn('referral_code');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn(['referral_code', 'referred_at']);
        });
    }
};
