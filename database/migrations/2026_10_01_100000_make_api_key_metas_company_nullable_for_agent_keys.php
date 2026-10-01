<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent Ingestion Gateway: machine-principal API keys (`agent:create-key`)
     * belong to a dedicated service-account user, not to a company, and are
     * minted from the CLI rather than through the two-person admin flow — so
     * `company_id`, `requested_by` and `approved_by` must be allowed to be
     * null. Additive relaxation only; every existing row keeps its values.
     * `kind` distinguishes `company` keys from `agent` keys.
     */
    public function up(): void
    {
        Schema::table('api_key_metas', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->unsignedBigInteger('requested_by')->nullable()->change();
            $table->unsignedBigInteger('approved_by')->nullable()->change();
            $table->string('kind', 20)->default('company');
        });
    }

    public function down(): void
    {
        Schema::table('api_key_metas', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
