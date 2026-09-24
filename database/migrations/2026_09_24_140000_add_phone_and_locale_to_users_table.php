<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile app's account settings screen (`PATCH /api/v1/auth/me`) lets a
 * user set their own phone number and preferred locale. Neither existed as a
 * persisted column on `users` — `App\Http\Middleware\SetLocale` only ever
 * reads the locale from the session/header, so a user's language choice did
 * not survive a fresh device/session. This adds both as nullable columns so
 * the API can persist them; `locale` is deliberately unconstrained here (no
 * DB check), the `in:` validation rule against
 * `SetLocale::SUPPORTED` at the request layer is the source of truth for
 * which values are accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('locale', 10)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'locale']);
        });
    }
};
