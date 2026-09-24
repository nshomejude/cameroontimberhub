<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 160);
            $table->string('category', 32);
            $table->string('status', 16)->default('open');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_activity_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status IN ('open','pending','resolved','closed'))");
            DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_category_check CHECK (category IN ('account','order','payment','verification','listing','technical','other'))");
        }

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
