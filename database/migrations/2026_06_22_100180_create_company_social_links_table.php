<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_social_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('url', 255);
            $table->timestampsTz();

            $table->unique(['company_id', 'platform']);
        });

        DB::statement("ALTER TABLE company_social_links ADD CONSTRAINT company_social_links_platform_check CHECK (platform IN ('linkedin','facebook','instagram','youtube','x','wechat','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_social_links');
    }
};
