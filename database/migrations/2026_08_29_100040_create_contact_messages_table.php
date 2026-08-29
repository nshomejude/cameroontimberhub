<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists contact-form submissions (gap-plan 0.4b-ii). Previously this
 * form only sent an email (ContactMessageMail) with no persisted record --
 * the one public intake flow with no audit trail, unlike Rfq/CompanyInquiry.
 * Gives it a real subject to attach a Consent row to instead of requiring
 * consents.subject_id to become nullable for one caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 120);
            $table->string('company', 160)->nullable();
            $table->string('email', 180);
            $table->string('phone', 40)->nullable();
            $table->string('subject', 200);
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
