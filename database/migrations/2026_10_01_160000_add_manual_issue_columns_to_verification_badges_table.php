<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desk-verified badges: staff may issue a badge without approved backing
 * documents (e.g. agent-sourced companies with no owner to upload them). The
 * flag + mandatory reason keep those badges distinguishable and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_badges', function (Blueprint $table) {
            $table->boolean('issued_manually')->default(false);
            $table->text('manual_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('verification_badges', function (Blueprint $table) {
            $table->dropColumn(['issued_manually', 'manual_reason']);
        });
    }
};
