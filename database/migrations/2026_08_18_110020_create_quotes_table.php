<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // The routing row that entitled this supplier to quote. Nullable so
            // an admin-created quote survives a routing row being removed.
            $table->foreignId('rfq_company_id')->nullable()->constrained('rfq_company')->nullOnDelete();
            $table->string('reference_code', 40)->unique();
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3)->default('USD');
            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('shipping_amount', 14, 2)->nullable();
            $table->decimal('tax_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('incoterm', 10)->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->integer('validity_days')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('rfq_id');
            $table->index(['company_id', 'status']);
            $table->index('status');
            $table->index('valid_until');
        });

        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('draft','submitted','viewed','accepted','declined','withdrawn','expired'))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_incoterm_check CHECK (incoterm IS NULL OR incoterm IN ('FOB','CIF','CFR','EXW','DAP','other'))");
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_amounts_check CHECK (subtotal_amount >= 0 AND total_amount >= 0)');

        // One *active* quote per supplier per RFQ. Withdrawn quotes are excluded
        // so a supplier who withdraws can quote again, and soft-deleted rows are
        // excluded so deletion frees the slot. Enforced by the database rather
        // than by application code because two concurrent submits would race a
        // PHP-side guard.
        DB::statement("CREATE UNIQUE INDEX quotes_active_per_supplier_unique ON quotes (rfq_id, company_id) WHERE deleted_at IS NULL AND status <> 'withdrawn'");

        // At most one accepted quote per RFQ — the award is exclusive.
        DB::statement("CREATE UNIQUE INDEX quotes_single_accepted_unique ON quotes (rfq_id) WHERE deleted_at IS NULL AND status = 'accepted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
