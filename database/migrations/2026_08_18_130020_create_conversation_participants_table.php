<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Which side this row speaks for. Supplier rows also carry the
            // company so a message they post is attributed to the company, not
            // just the individual staff member.
            $table->string('role', 12);
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // The read cursor. Unread is DERIVED from it — there is no counter
            // to drift out of sync with the messages table.
            //
            // The cursor is the last-read message ID, not a timestamp: Eloquent
            // writes datetimes at whole-second precision, so a message posted
            // in the same second as a read would compare equal to it and be
            // silently swallowed. A monotonic bigint has no such tie. The
            // timestamp is kept alongside purely as a human-readable "seen at".
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestampTz('last_read_at', 6)->nullable();

            $table->boolean('is_starred')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->boolean('notify_email')->default(true);

            $table->timestampsTz(6);

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'is_archived']);
        });

        DB::statement("ALTER TABLE conversation_participants ADD CONSTRAINT conversation_participants_role_check CHECK (role IN ('buyer','supplier'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
