<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment provider. `credentials` is a single encrypted
        // JSON blob holding that provider's key/value pairs (subscription_key,
        // api_user, client_secret, …) and is written ONLY via the gated
        // Request/Approve flow (App\Actions\Payments) — never a plain admin
        // form field, so there is no "just paste the key here" shortcut that
        // skips the two-person + fresh-2FA gate. Mirrors ai_settings.
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('environment')->default('sandbox'); // sandbox | production | live
            $table->text('credentials')->nullable(); // encrypted:array JSON blob
            $table->boolean('is_live')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
