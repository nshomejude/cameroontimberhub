<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Webhook signing hardening (architecture plan Task 0.5 follow-up —
     * bring delivery up to Stripe/GitHub conventions).
     *
     * webhook_subscriptions: replace the one-way `secret_hash` (sha256 of a
     * plaintext shown once) with an encrypted-at-rest `secret` column — the
     * plaintext IS the HMAC key (Stripe/GitHub `whsec_...` style), stored
     * via Laravel's `encrypted` cast (Crypt::encryptString under the hood,
     * same at-rest posture as App\Models\AiSetting's api_key_encrypted).
     * A plaintext cannot be recovered from the old hash, so existing rows
     * (none on production — webhooks are new this cycle and no API keys are
     * configured) simply lose their secret and must be regenerated.
     *
     * webhook_deliveries: add `event_id` (ULID, generated once when the
     * delivery row is created) so every retry of the same delivery carries
     * the same envelope `id` — the consumer's idempotency key.
     */
    public function up(): void
    {
        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->text('secret')->nullable()->after('event_types');
        });

        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->dropColumn('secret_hash');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('event_id')->nullable()->after('event_type');
        });

        DB::table('webhook_deliveries')->whereNull('event_id')->orderBy('id')
            ->each(function ($row) {
                DB::table('webhook_deliveries')->where('id', $row->id)
                    ->update(['event_id' => (string) Str::ulid()]);
            });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('event_id')->nullable(false)->change();
            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_subscriptions', function (Blueprint $table) {
            $table->string('secret_hash')->nullable();
            $table->dropColumn('secret');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};
