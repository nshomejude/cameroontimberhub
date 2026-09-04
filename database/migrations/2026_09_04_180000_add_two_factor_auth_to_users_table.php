<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §39: TOTP-based multi-factor authentication + step-up re-auth for
 * high-risk actions. Additive columns only, mirroring the same pattern as the
 * 2026_09_04_170000_add_login_tracking_to_users_table migration.
 *
 * `two_factor_secret` and `two_factor_recovery_codes` are stored via
 * Laravel's `encrypted` cast (see App\Models\User), so they land in the
 * database already ciphertext — the columns are declared `text` to comfortably
 * hold the encrypted payload. `two_factor_confirmed_at` is the marker that
 * 2FA is actually active (a generated-but-unconfirmed secret does not count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('known_login_ips');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestampTz('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
