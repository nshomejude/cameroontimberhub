<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Threaded response log for the Counterparty Response stage of a Dispute. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->text('body');

            $table->timestamp('created_at')->nullable();

            $table->index('dispute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
    }
};
