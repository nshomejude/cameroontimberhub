<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One round of a negotiation over a quote.
 *
 * A counter-offer is NOT a quote. A buyer cannot issue a quote — only a routed
 * supplier can — so modelling the buyer's "570,000 instead of 620,000" as a
 * Quote row would either break the routing invariant or require a fake supplier
 * company. It is also not always an offer that will ever become a quote: most
 * rounds are declined or superseded. So each round is recorded here, and only
 * *agreement* mints a revised Quote (see the revision chain migration).
 *
 * The table is an append-only ledger: rows move pending -> accepted/declined/
 * superseded and are never edited afterwards, so the thread can replay the
 * exact sequence of what each side proposed and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_counter_offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // Who proposed it. `party` is derived from the conversation at write
            // time and stored, so the ledger stays readable even if a staff
            // member later leaves the company.
            $table->foreignId('proposed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('party', 10);

            $table->char('currency', 3);
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('unit', 10)->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total_amount', 14, 2);
            $table->string('incoterm', 10)->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->text('note')->nullable();

            $table->string('status', 12)->default('pending');
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();

            // The revised quote this round produced, when it was accepted.
            $table->foreignId('resulting_quote_id')->nullable()->constrained('quotes')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['quote_id', 'id']);
            $table->index('conversation_id');
        });

        DB::statement("ALTER TABLE quote_counter_offers ADD CONSTRAINT quote_counter_offers_party_check CHECK (party IN ('buyer','supplier'))");
        DB::statement("ALTER TABLE quote_counter_offers ADD CONSTRAINT quote_counter_offers_status_check CHECK (status IN ('pending','accepted','declined','superseded'))");
        DB::statement("ALTER TABLE quote_counter_offers ADD CONSTRAINT quote_counter_offers_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement('ALTER TABLE quote_counter_offers ADD CONSTRAINT quote_counter_offers_amounts_check CHECK (unit_price > 0 AND total_amount > 0 AND (quantity IS NULL OR quantity > 0))');

        // At most one live round per quote. This is what makes the negotiation
        // strictly turn-based: you cannot stack three counters on a quote while
        // the other side has not answered the first, and two concurrent
        // counters cannot both become "the" pending offer.
        DB::statement("CREATE UNIQUE INDEX quote_counter_offers_single_pending_unique ON quote_counter_offers (quote_id) WHERE status = 'pending'");

        // At most one accepted round per quote — agreement happens once.
        DB::statement("CREATE UNIQUE INDEX quote_counter_offers_single_accepted_unique ON quote_counter_offers (quote_id) WHERE status = 'accepted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_counter_offers');
    }
};
