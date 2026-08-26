<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // Both nullable: a System message has neither, a buyer message has
            // only the user, a supplier message has both (the staff member who
            // typed it and the company it speaks for).
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sender_company_id')->nullable()->constrained('companies')->nullOnDelete();

            // ONE table for every kind of message. `type` selects the Blade
            // partial, `payload` carries the immutable snapshot that partial
            // needs, and the morph points at the live record when there is one.
            // Phases 2-4 (quotation card, proforma, shipment update, review
            // request) are new `type` values and new partials — no schema churn.
            $table->string('type', 32)->default('text');
            $table->text('body')->nullable();
            $table->jsonb('payload')->nullable();

            $table->string('related_type', 60)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->foreignId('reply_to_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestampsTz(6);
            $table->softDeletesTz();

            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });

        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('text','system','order_reference','order_status','product_reference'))");
        // A message must say something: free text, or a structured payload.
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_content_check CHECK (body IS NOT NULL OR payload IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
