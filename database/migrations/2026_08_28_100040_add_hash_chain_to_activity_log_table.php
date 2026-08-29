<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident hash chain over the activity log (gap-plan 0.5, brief
 * §3.9 / §6.9's "all actions in audit log" clause). Mirrors Document's
 * existing hash/prev_hash pattern (app/Models/Document.php) applied to a
 * second, general-purpose model. The chain is GLOBAL (one sequence across
 * every logged activity, in insertion order) rather than per-subject,
 * because the audit trail's integrity claim is "nothing was inserted,
 * deleted, or reordered in this table," not "this one entity's history is
 * intact" -- a global chain is the only shape that can prove that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->char('hash', 64)->nullable()->after('properties');
            $table->char('prev_hash', 64)->nullable()->after('hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn(['hash', 'prev_hash']);
        });
    }
};
