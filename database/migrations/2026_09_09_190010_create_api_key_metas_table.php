<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sanctum's own `personal_access_tokens` table already stores the
     * hashed token, `name`, `abilities` and `tokenable` — everything the
     * token/ability system needs. It has no room for which COMPANY the
     * key is scoped to, nor a rate-limit tier, nor who requested/approved
     * its issuance under the two-person control. Rather than duplicating
     * Sanctum's hash/ability storage in a parallel `api_keys` table, this
     * is a thin ADDITIVE side-table keyed 1:1 to a personal_access_tokens
     * row, carrying only the fields Sanctum genuinely lacks.
     */
    public function up(): void
    {
        Schema::create('api_key_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('rate_limit_tier')->default('standard');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('personal_access_token_id');
            $table->index(['company_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_metas');
    }
};
