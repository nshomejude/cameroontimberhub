<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the blueprint's §4 Trust Architecture tier (Level 0-5) ON TOP OF the
 * existing polymorphic Verification workflow / VerificationBadge model —
 * neither is touched or replaced.
 *
 * Placed directly on `companies` rather than on `verification_badges`: a
 * badge row models one issued, revocable, dated credential of a specific
 * `badge_type` (spec: verified_company, sigif_registered, ...), and a
 * company can hold several simultaneously or none. The tier is a different
 * shape of fact — a single scalar "how far has this company's trust profile
 * progressed" summary that must exist even for a company with zero badge
 * rows (tier 0, the common case). That matches how the platform already
 * models single-valued company state (`status`, `plan_id`, `verified_at`)
 * directly on `companies`, so the tier joins them there instead of being
 * shoehorned into a table shaped for multiple discrete credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->smallInteger('verification_tier')->default(0)->after('verification_expires_at');
        });

        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_verification_tier_check CHECK (verification_tier BETWEEN 0 AND 5)');
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('verification_tier');
        });
    }
};
