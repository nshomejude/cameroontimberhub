<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4: the reorder request card.
 *
 * `messages_type_check` is a closed CHECK constraint listing every legal value
 * verbatim so the database and the `MessageType` enum cannot drift apart, so a
 * new card costs exactly one statement here — the same pattern Phases 2 and 3
 * used. Phase 4 adds ONE type: everything after the reorder request (the
 * quotation, the order reference, the proforma, the payment request, the
 * payment confirmation, the status trail) is an existing card, because a
 * reorder produces a genuinely ordinary order and must not look special.
 */
return new class extends Migration
{
    private const PHASE_1 = "'text','system','order_reference','order_status','product_reference'";

    private const PHASE_2 = "'rfq_reference','quotation','counter_offer','contract_acceptance'";

    private const PHASE_3 = "'proforma_invoice','payment_request','payment_confirmed','shipment_update','order_delivered','order_documents','transaction_completed','company_review'";

    private const PHASE_4 = "'reorder_request'";

    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_type_check');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('.self::PHASE_1.','.self::PHASE_2.','.self::PHASE_3.','.self::PHASE_4.'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_type_check');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('.self::PHASE_1.','.self::PHASE_2.','.self::PHASE_3.'))');
    }
};
