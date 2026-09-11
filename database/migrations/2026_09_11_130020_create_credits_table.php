<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M8 (plan §19) — per-company non-cash credit ledger.
 *
 * Append-only: `amount` is positive for a grant, negative for a
 * consumption. A company's balance is the SUM of its rows for a given
 * currency (see App\Services\Billing\CreditLedger), never a mutable
 * balance column, so the full history stays reconstructible and a
 * correction is a new row rather than an edit of an old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->decimal('amount', 14, 2); // positive = grant, negative = consumption
            $table->char('currency', 3);
            $table->text('reason')->nullable();
            $table->string('source', 30); // App\Enums\CreditSource
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credits');
    }
};
