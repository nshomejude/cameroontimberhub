<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->bigIncrements('id');

            // A conversation is always between ONE buyer account and ONE
            // supplier company. Everything else (RFQ, quote, order, product) is
            // optional context that the later chat-commerce phases hang cards
            // off — nullable so a plain "message this supplier" works on day one.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('rfq_id')->nullable()->constrained('rfqs')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('subject', 180)->nullable();
            // "Message about" chips in the New Conversation mockup.
            $table->string('topic', 24)->default('general');
            $table->string('status', 16)->default('open');

            $table->timestampTz('last_message_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz();

            // Inbox ordering, and the "does a thread already exist" lookup that
            // Message-Supplier uses so a buyer never accumulates duplicates.
            $table->index(['user_id', 'last_message_at']);
            $table->index(['company_id', 'last_message_at']);
            $table->index(['user_id', 'company_id']);
            $table->index('order_id');
            $table->index('product_id');
        });

        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('open','archived','closed'))");
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_topic_check CHECK (topic IN ('general','rfq','order','product','support'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
