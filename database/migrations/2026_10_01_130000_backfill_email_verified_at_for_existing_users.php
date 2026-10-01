<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification (MustVerifyEmail) arrived after accounts already
 * existed. Without this backfill every pre-existing user would be treated as
 * unverified on deploy day: blocked from starting conversations and shown the
 * verify banner. Accounts that exist when this runs are grandfathered as
 * verified; only accounts created afterwards must click the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, NOW())')]);
    }

    public function down(): void
    {
        // Irreversible by design: we cannot tell grandfathered rows apart.
    }
};
