<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Notify me when the mobile app is out" sign-ups from /mobile-app.
 *
 * A dedicated table rather than `leads` or `company_inquiries`: both of those
 * are hard-scoped to a company (leads.company_id is NOT NULL, an inquiry is by
 * definition addressed to one supplier) and both feed the exporter CRM. An app
 * launch notice belongs to nobody's pipeline. The contact form persists
 * nothing at all — it only mails — so it could not hold the list either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_launch_subscribers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email', 180);
            // Lowercased copy of `email`, used for the uniqueness guarantee so
            // Buyer@x.com and buyer@x.com cannot both subscribe.
            $table->string('email_normalised', 180);
            $table->string('platform', 10)->default('any');
            $table->string('source', 40)->default('mobile-app-page');
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();

            $table->unique('email_normalised');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE app_launch_subscribers ADD CONSTRAINT app_launch_subscribers_platform_check CHECK (platform IN ('any','android','ios'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_launch_subscribers');
    }
};
