<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §25 login-anomaly detection needs *some* record of a user's
 * established login history to compare a new login against. Nothing is
 * currently recorded anywhere in the app (no login observer, no session
 * table columns keyed for lookup by user).
 *
 * Chosen approach: a handful of nullable columns directly on `users`
 * (last_login_at/ip/user_agent + a small jsonb array of recently-seen IPs),
 * rather than a separate `user_login_history` table. This is the less
 * invasive option for a lightweight heuristic: no new model/migration pair,
 * no join needed to answer "have we seen this IP before for this user", and
 * the rolling `known_login_ips` list (capped, see FraudDetectionService) is
 * enough signal for a simple new-IP heuristic without needing to query a
 * full history table. A dedicated append-only history table would be the
 * better choice if this needed to power an admin-facing "login history"
 * screen later; it does not today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('last_login_at')->nullable()->after('remember_token');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->string('last_login_user_agent', 500)->nullable()->after('last_login_ip');
            $table->jsonb('known_login_ips')->nullable()->after('last_login_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'last_login_ip', 'last_login_user_agent', 'known_login_ips']);
        });
    }
};
