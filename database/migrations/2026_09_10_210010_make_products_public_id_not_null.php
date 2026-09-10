<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the shared backfill defensively, then locks `products.public_id` to
 * NOT NULL now that every row is guaranteed to have one. The backfill logic
 * lives in one place (BackfillProductPublicIdsCommand / ProductIdentifier)
 * and is invoked here rather than duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('products:backfill-public-ids');

        Schema::table('products', function (Blueprint $table): void {
            $table->string('public_id', 40)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('public_id', 40)->nullable()->change();
        });
    }
};
