<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace-commission COLLECTION (owner decision 2026-10-01): commission
 * charged on orders is billed to each supplier on a monthly statement and
 * paid by manual deposit (MTN MoMo / Orange Money transfer or bank deposit)
 * to the platform — there is no automatic debit.
 *
 * - `commission_payment_settings`: singleton row with the platform's MoMo /
 *   Orange Money numbers and bank details, edited by finance in /admin and
 *   printed on every statement.
 * - `commission_statements`: one per supplier company per currency per month
 *   (`App\Services\Commission\CommissionStatementIssuer`). Hash-chained like
 *   invoices (ChainsIntegrity); the issued amounts never change, only the
 *   payment state (`amount_paid`, `status`) and void columns move. At most one
 *   NON-void statement per company + period + currency (partial unique index)
 *   — voiding one lets the period be re-issued.
 * - `commission_statement_lines`: `charge` lines bill an order's net
 *   commission once (partial unique on order_id while the line is not void);
 *   `adjustment` lines carry credits issued after the order was billed
 *   (negative amounts) onto the next statement.
 * - `commission_deposits`: a supplier-reported (or finance-recorded) deposit
 *   against a statement, confirmed or rejected by finance. One transaction
 *   reference per method may only be used once (partial unique, rejected
 *   deposits excluded so a typo can be re-reported).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payment_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('mtn_momo_number', 40)->nullable();
            $table->string('mtn_momo_name', 150)->nullable();
            $table->string('orange_money_number', 40)->nullable();
            $table->string('orange_money_name', 150)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->string('bank_account_number', 60)->nullable();
            $table->string('bank_iban', 60)->nullable();
            $table->string('bank_swift', 20)->nullable();
            $table->string('bank_branch', 150)->nullable();
            $table->text('extra_instructions')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('commission_statements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('statement_number', 40)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->string('status', 20)->default('issued');
            $table->decimal('charges_amount', 14, 2);
            $table->decimal('adjustments_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->timestampTz('issued_at');
            $table->date('due_date');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('due_reminder_sent_at')->nullable();
            $table->timestampTz('overdue_notified_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->char('hash', 64)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        DB::statement("ALTER TABLE commission_statements ADD CONSTRAINT commission_statements_status_check CHECK (status IN ('issued','partially_paid','paid','overdue','void'))");
        DB::statement("CREATE UNIQUE INDEX commission_statements_one_per_period ON commission_statements (company_id, period_start, currency) WHERE status <> 'void'");

        Schema::create('commission_statement_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('commission_statement_id')->constrained('commission_statements')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 12)->default('charge');
            $table->string('order_reference', 40);
            $table->timestampTz('charged_at')->nullable();
            $table->decimal('order_subtotal', 14, 2)->default(0);
            $table->decimal('commission_rate', 6, 4)->nullable();
            $table->decimal('commission_amount', 14, 2)->default(0);
            $table->decimal('credited_amount', 14, 2)->default(0);
            $table->decimal('amount', 14, 2);
            $table->string('description', 255)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->index('order_id');
        });

        DB::statement("ALTER TABLE commission_statement_lines ADD CONSTRAINT commission_statement_lines_kind_check CHECK (kind IN ('charge','adjustment'))");
        DB::statement("CREATE UNIQUE INDEX commission_statement_lines_order_billed_once ON commission_statement_lines (order_id) WHERE kind = 'charge' AND voided_at IS NULL");

        Schema::create('commission_deposits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('commission_statement_id')->constrained('commission_statements')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('method', 20);
            $table->string('source', 12)->default('supplier');
            $table->string('status', 12)->default('pending');
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_received', 14, 2)->nullable();
            $table->string('transaction_reference', 100);
            $table->string('reference_key', 100);
            $table->date('paid_on');
            $table->string('proof_disk', 40)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_original_name', 255)->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("ALTER TABLE commission_deposits ADD CONSTRAINT commission_deposits_method_check CHECK (method IN ('mtn_momo','orange_money','bank'))");
        DB::statement("ALTER TABLE commission_deposits ADD CONSTRAINT commission_deposits_status_check CHECK (status IN ('pending','confirmed','rejected'))");
        DB::statement("ALTER TABLE commission_deposits ADD CONSTRAINT commission_deposits_source_check CHECK (source IN ('supplier','admin'))");
        DB::statement("CREATE UNIQUE INDEX commission_deposits_reference_once_per_method ON commission_deposits (method, reference_key) WHERE status <> 'rejected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_deposits');
        Schema::dropIfExists('commission_statement_lines');
        Schema::dropIfExists('commission_statements');
        Schema::dropIfExists('commission_payment_settings');
    }
};
