<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer reviews of a supplier, earned by a real completed order.
 *
 * `companies.rating_avg` / `rating_count` existed before this table did, with
 * nothing behind them. From here on they are DERIVED — recomputed from the
 * published rows in this table by CompanyReviewService::recompute() — so the
 * number on a supplier profile is always the arithmetic of real reviews.
 *
 * One review per order, enforced by the database and not merely by a PHP check:
 * `order_id` carries a UNIQUE index. That is stricter than "one per buyer per
 * order" and implies it, since an order has exactly one buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The order is the eligibility proof, so it is required and unique.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->unsignedSmallInteger('rating');
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();

            $table->string('status', 20)->default('published');

            // Snapshot of who wrote it, so a later account rename does not
            // silently rewrite an attributed review.
            $table->string('author_name', 160);
            $table->string('author_company', 200)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->unique(['user_id', 'order_id']);
        });

        DB::statement('ALTER TABLE company_reviews ADD CONSTRAINT company_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE company_reviews ADD CONSTRAINT company_reviews_status_check CHECK (status IN ('published','pending','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_reviews');
    }
};
