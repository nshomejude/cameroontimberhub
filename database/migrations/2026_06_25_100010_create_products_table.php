<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('species')->nullOnDelete();
            $table->string('slug', 200)->unique();
            $table->string('name', 200);
            $table->string('product_type', 30);
            $table->text('description')->nullable();

            $table->decimal('price_amount', 14, 2)->nullable();
            $table->char('price_currency', 3)->default('XAF');
            $table->string('price_unit', 10)->default('m3');
            $table->decimal('moq_quantity', 12, 2)->nullable();
            $table->string('moq_unit', 10)->default('m3');

            $table->string('grade', 120)->nullable();
            $table->decimal('thickness_mm', 8, 2)->nullable();
            $table->decimal('width_min_mm', 8, 2)->nullable();
            $table->decimal('width_max_mm', 8, 2)->nullable();
            $table->decimal('length_min_m', 6, 2)->nullable();
            $table->decimal('length_max_m', 6, 2)->nullable();
            $table->string('moisture_content', 60)->nullable();
            $table->string('origin', 120)->default('Cameroon');
            $table->string('certification', 150)->nullable();

            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_best_seller')->default(false);

            $table->decimal('rating', 2, 1)->nullable();
            $table->integer('reviews_count')->default(0);
            $table->integer('buyers_count')->default(0);

            $table->jsonb('specifications')->nullable();
            $table->jsonb('key_benefits')->nullable();
            $table->string('primary_image_path', 512)->nullable();

            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'status']);
            $table->index('species_id');
            $table->index('product_type');
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','active','archived'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (product_type IN ('sawn_timber','logs','veneer','flooring','decking','mouldings','plywood','beams'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_price_unit_check CHECK (price_unit IN ('m3','m2','pcs','ton'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_moq_unit_check CHECK (moq_unit IN ('m3','m2','pcs','ton'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_rating_check CHECK (rating IS NULL OR (rating >= 0 AND rating <= 5))');

        DB::statement("ALTER TABLE products ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(grade, '')), 'B') ||
            setweight(to_tsvector('english', coalesce(description, '')), 'C')
        ) STORED");

        DB::statement('CREATE INDEX products_search_vector_gin ON products USING gin (search_vector)');
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (name gin_trgm_ops)');
        // Landing page: "Popular Timber Products" reads active + featured only.
        DB::statement("CREATE INDEX products_active_featured_idx ON products (is_featured, created_at) WHERE status = 'active' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
