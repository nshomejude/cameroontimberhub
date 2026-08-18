<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            // RFQ intake stays account-free, so this is nullable: it is only
            // filled when `buyer_email` matches a registered user (at creation,
            // or backfilled when that address later registers). It is what lets
            // a signed-in buyer reach their own RFQs without a signed link.
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->index('user_id');
        });

        // Backfill guest RFQs whose address already has an account.
        DB::statement('UPDATE rfqs SET user_id = users.id FROM users WHERE lower(rfqs.buyer_email) = lower(users.email) AND rfqs.user_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
