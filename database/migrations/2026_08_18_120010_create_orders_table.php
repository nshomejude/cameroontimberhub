<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            // The accepted quote is the order's origin. Restricted rather than
            // cascading: an order is a commercial record and must not vanish
            // because the quote row was deleted.
            $table->foreignId('quote_id')->constrained('quotes')->restrictOnDelete();
            $table->foreignId('rfq_id')->constrained('rfqs')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            // Buyer account, when the RFQ had one. Guests reach the order via
            // the same signed-link scheme as their quotes (BuyerRfqAccess).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reference_code', 40)->unique();
            $table->string('status', 20)->default('awarded');

            // Buyer snapshot — frozen at award time so a later profile edit
            // never rewrites the history of a placed order.
            $table->string('buyer_name', 255);
            $table->string('buyer_company', 255)->nullable();
            $table->string('buyer_email', 255);
            $table->char('buyer_country_code', 2)->nullable();

            // Supplier snapshot — same reasoning.
            $table->string('supplier_name', 255);

            $table->char('currency', 3)->default('USD');
            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('shipping_amount', 14, 2)->nullable();
            $table->decimal('tax_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);

            // Payment state. This platform has NO payment integration: nothing
            // here is ever set by the system. It is an administrative record of
            // a settlement that happened off-platform, set by staff only.
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->string('payment_method', 60)->nullable();
            $table->timestampTz('payment_recorded_at')->nullable();

            $table->string('incoterm', 10)->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->char('destination_country_code', 2)->nullable();
            $table->string('shipping_port', 120)->nullable();
            $table->date('expected_delivery_at')->nullable();
            $table->text('buyer_notes')->nullable();

            $table->timestampTz('awarded_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('production_started_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestampsTz();

            $table->index('rfq_id');
            $table->index(['company_id', 'status']);
            $table->index('status');
            $table->index('user_id');
            $table->index('buyer_email');
        });

        // Exactly one order per accepted quote, enforced by the database so two
        // concurrent accepts cannot both mint an order.
        DB::statement('CREATE UNIQUE INDEX orders_quote_unique ON orders (quote_id)');

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('awarded','confirmed','in_production','shipped','delivered','completed','cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid','partially_paid','paid'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_check CHECK (currency IN ('XAF','USD','EUR','GBP','CNY'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_incoterm_check CHECK (incoterm IS NULL OR incoterm IN ('FOB','CIF','CFR','EXW','DAP','other'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (subtotal_amount >= 0 AND total_amount >= 0 AND amount_paid >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
