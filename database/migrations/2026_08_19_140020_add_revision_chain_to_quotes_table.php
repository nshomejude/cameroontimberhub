<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quote revision chain.
 *
 * When a negotiation settles on new terms the supplier's offer is re-issued as
 * a *new* Quote rather than by editing the old one in place: the old figures
 * are what the buyer saw when they countered, and rewriting them would rewrite
 * the negotiation's own evidence.
 *
 * The existing partial unique index `quotes_active_per_supplier_unique` allows
 * exactly one non-withdrawn quote per (rfq, company), so the predecessor is
 * withdrawn first. `supersedes_quote_id` is what lets the thread render that
 * predecessor honestly as "Revised" rather than as a bare "Withdrawn", which
 * would read as the supplier having walked away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('supersedes_quote_id')->nullable()->after('rfq_company_id')
                ->constrained('quotes')->nullOnDelete();
            $table->unsignedSmallInteger('revision')->default(1)->after('reference_code');
        });

        // A quote can be superseded at most once — the chain is linear, never a
        // tree, so "the current revision" is always a single unambiguous row.
        DB::statement('CREATE UNIQUE INDEX quotes_supersedes_unique ON quotes (supersedes_quote_id) WHERE supersedes_quote_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_revision_check CHECK (revision >= 1)');
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_supersedes_not_self_check CHECK (supersedes_quote_id IS NULL OR supersedes_quote_id <> id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_supersedes_not_self_check');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_revision_check');
        DB::statement('DROP INDEX IF EXISTS quotes_supersedes_unique');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_quote_id');
            $table->dropColumn('revision');
        });
    }
};
