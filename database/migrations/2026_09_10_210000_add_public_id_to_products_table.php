<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-plan §1.1: every marketplace listing gets a stable public identifier
 * (`CTH-CMR-XXX-00000`) used on the QR code and the public verification page.
 *
 * Additive + nullable here; a follow-up migration backfills every row and
 * sets NOT NULL once the backfill is proven. The per-species counter lives
 * in `product_public_id_sequences`, locked in a transaction the same way
 * RfqReferenceGenerator guards its sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('public_id', 40)->nullable()->unique()->after('slug');
        });

        Schema::create('product_public_id_sequences', function (Blueprint $table): void {
            $table->string('letters', 3)->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_public_id_sequences');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
