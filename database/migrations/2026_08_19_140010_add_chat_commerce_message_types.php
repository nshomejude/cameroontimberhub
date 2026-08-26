<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 message types.
 *
 * The typed-message contract says a new card is "an enum case plus a Blade
 * partial, no migration". That is true of the *table* — nothing here adds a
 * column, a relation or an index. But `messages_type_check` was written as a
 * closed CHECK constraint listing the Phase 1 values verbatim, deliberately, so
 * that the database and the enum cannot drift. Widening that list is therefore
 * the one unavoidable schema statement, and it is exactly one statement.
 */
return new class extends Migration
{
    private const PHASE_1 = "'text','system','order_reference','order_status','product_reference'";

    private const PHASE_2 = "'rfq_reference','quotation','counter_offer','contract_acceptance'";

    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_type_check');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('.self::PHASE_1.','.self::PHASE_2.'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_type_check');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('.self::PHASE_1.'))');
    }
};
