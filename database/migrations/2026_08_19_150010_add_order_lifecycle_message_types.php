<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 message types: the order lifecycle rendered inside the thread.
 *
 * Same reasoning as the Phase 2 widening — `messages_type_check` is a closed
 * CHECK constraint listing every legal value verbatim so the database and the
 * `MessageType` enum cannot drift apart. Adding cards therefore costs exactly
 * one statement here and nothing else in the schema.
 */
return new class extends Migration
{
    private const PHASE_1 = "'text','system','order_reference','order_status','product_reference'";

    private const PHASE_2 = "'rfq_reference','quotation','counter_offer','contract_acceptance'";

    private const PHASE_3 = "'proforma_invoice','payment_request','payment_confirmed','shipment_update','order_delivered','order_documents','transaction_completed','company_review'";

    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_type_check');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('.self::PHASE_1.','.self::PHASE_2.','.self::PHASE_3.'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_type_check');
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('.self::PHASE_1.','.self::PHASE_2.'))');
    }
};
