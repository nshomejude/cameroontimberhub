<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An audit record of a buyer accepting a quotation's terms in a thread.
 *
 * This is NOT an electronic signature and the schema does not pretend to be
 * one: there is no certificate, no key material, no signing authority, no
 * timestamping authority and no identity assurance beyond "this platform
 * account was authenticated at the time". What it is, exactly, is a
 * tamper-evident record of *what was on screen* when someone clicked accept:
 *
 *  - `terms` is the verbatim snapshot of the figures the accept button was
 *    rendered next to (the same array the message payload carries);
 *  - `terms_hash` is sha256 over that snapshot in a canonical encoding, so the
 *    record can be re-derived and compared later;
 *  - actor, timestamp, IP and user-agent are recorded because they are the
 *    facts we actually observed.
 *
 * Rows are never updated. One acceptance per quote, enforced by a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_acceptances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->foreignId('accepted_by_user_id')->constrained('users')->cascadeOnDelete();
            // Snapshotted so the record reads correctly even if the account is
            // later renamed or the address changed.
            $table->string('accepted_by_name', 255);
            $table->string('accepted_by_email', 255);
            $table->string('party', 10)->default('buyer');

            $table->timestampTz('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->char('terms_hash', 64);
            $table->jsonb('terms');

            $table->timestampsTz();

            $table->index('conversation_id');
        });

        DB::statement("ALTER TABLE contract_acceptances ADD CONSTRAINT contract_acceptances_party_check CHECK (party IN ('buyer','supplier'))");
        DB::statement('CREATE UNIQUE INDEX contract_acceptances_quote_unique ON contract_acceptances (quote_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_acceptances');
    }
};
