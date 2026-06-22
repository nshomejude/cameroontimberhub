<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE company_user ADD CONSTRAINT company_user_role_check CHECK (role IN ('owner','manager','member'))");
        DB::statement('CREATE UNIQUE INDEX company_user_primary_idx ON company_user (company_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
