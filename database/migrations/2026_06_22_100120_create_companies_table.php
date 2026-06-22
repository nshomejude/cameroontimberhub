<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 180)->unique();
            $table->string('legal_name', 255);
            $table->string('trade_name', 255)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->string('sigif_operator_id', 100)->nullable();
            $table->jsonb('sigif_permit_numbers')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('description')->nullable();
            $table->smallInteger('year_founded')->nullable();
            $table->integer('employee_count')->nullable();
            $table->decimal('annual_capacity_m3', 14, 2)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->char('country_code', 2)->default('CM');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('logo_path', 512)->nullable();
            $table->string('cover_path', 512)->nullable();
            // FK to plans added in Phase E (plans table not yet created).
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('verification_expires_at')->nullable();
            $table->smallInteger('profile_completion')->default(0);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('region');
            $table->index('country_code');
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('draft','pending','verified','suspended','rejected','archived'))");

        DB::statement("ALTER TABLE companies ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(legal_name, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(trade_name, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(description, '')), 'B') ||
            setweight(to_tsvector('english', coalesce(city, '')), 'C') ||
            setweight(to_tsvector('english', coalesce(region, '')), 'C')
        ) STORED");

        DB::statement('CREATE INDEX companies_search_vector_gin ON companies USING gin (search_vector)');
        DB::statement('CREATE INDEX companies_legal_name_trgm ON companies USING gin (legal_name gin_trgm_ops)');
        DB::statement('CREATE INDEX companies_trade_name_trgm ON companies USING gin (trade_name gin_trgm_ops)');
        DB::statement('CREATE INDEX companies_status_active_idx ON companies (status) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX companies_featured_idx ON companies (is_featured) WHERE is_featured = true');
        DB::statement("CREATE INDEX companies_directory_idx ON companies (verified_at) WHERE status = 'verified' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
