<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for a reorder, plus the database-level idempotency guard.
 *
 * A reorder is NOT a copied order row. It is a new RFQ that happens to know
 * which order it repeats, which then travels the ordinary audited path —
 * supplier quotes it, buyer accepts, QuoteService::accept() mints the order. So
 * the link lives on `rfqs`, and `orders.reorder_of_order_id` is carried across
 * at award time purely so a card can honestly say "Reorder" without walking
 * back through the quote.
 *
 * The partial unique index is the real double-submit guard. Two rapid clicks
 * (or two tabs) race to insert a reorder RFQ for the same source order; exactly
 * one wins, and the loser gets a unique violation which ReorderService turns
 * into a plain refusal. It is scoped to OPEN reorders only — once the reorder
 * RFQ is closed (a quote was accepted), rejected or marked spam, the buyer is
 * free to order the same thing again, which is the entire point of a reorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->foreignId('reorder_of_order_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('reorder_of_order_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX rfqs_open_reorder_unique
                ON rfqs (reorder_of_order_id)
                WHERE reorder_of_order_id IS NOT NULL
                  AND status IN ('new', 'in_review', 'approved')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rfqs_open_reorder_unique');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reorder_of_order_id');
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reorder_of_order_id');
        });
    }
};
