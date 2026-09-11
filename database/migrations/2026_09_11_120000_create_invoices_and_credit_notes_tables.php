<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine M4 — immutable invoices + credit notes.
 *
 * An `Invoice` is auto-issued (born `paid`) when a plan `Payment` completes.
 * It is hash-chained (ChainsIntegrity) and its issued facts never change; a
 * correction is a separate `CreditNote` against it — the invoice row is never
 * edited. Both number series are allocated from locked `*_sequences` rows,
 * mirroring App\Support\CarbonProjectIdentifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('invoice_number', 40)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('tax_rule_id')->nullable()->constrained('tax_rules')->nullOnDelete();
            $table->string('status', 16)->default('paid');
            $table->char('currency', 3);
            $table->decimal('subtotal_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('tax_rate', 6, 4)->nullable();
            $table->string('tax_label', 120)->nullable();
            $table->jsonb('bill_to');
            $table->jsonb('bill_from');
            $table->timestampTz('issued_at');
            $table->text('notes')->nullable();
            $table->char('hash', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'issued_at']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('issued','paid','void'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->timestampsTz();

            $table->index('invoice_id');
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('credit_note_number', 40)->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('issued');
            $table->char('currency', 3);
            $table->decimal('subtotal_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->text('reason');
            $table->timestampTz('issued_at');
            $table->char('hash', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->timestampsTz();

            $table->index('invoice_id');
        });

        DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_status_check CHECK (status IN ('issued','void'))");
        DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT credit_notes_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");

        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->timestampsTz();

            $table->index('credit_note_id');
        });

        // Per-year monotonic counters for CTH-INV-YYYY-NNNNN / CTH-CN-YYYY-NNNNN.
        // One row per (series, year), taken with lockForUpdate in a transaction
        // — mirrors product_public_id_sequences.
        Schema::create('billing_document_sequences', function (Blueprint $table): void {
            $table->string('series', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_value')->default(0);
            $table->timestampsTz();

            $table->primary(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_sequences');
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
