<?php

use App\Models\CompanyDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalizes document_reminder_logs from a hard FK to company_documents
 * into a polymorphic owner (document_owner_type/document_owner_id), so
 * SendDocumentExpiryReminderJob can log a reminder for either a
 * CompanyDocument row (unchanged, still the majority of real data) or a
 * polymorphic Document row (gap-plan item 0.1). Additive-first within this
 * one migration: add the new nullable columns, backfill them from the
 * existing company_document_id, then drop the old FK/column and make the
 * new columns required -- safe here because reminder-log row counts are
 * small (one row per document per threshold, not per document itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_reminder_logs', function (Blueprint $table) {
            $table->string('document_owner_type', 120)->nullable()->after('id');
            $table->unsignedBigInteger('document_owner_id')->nullable()->after('document_owner_type');
        });

        DB::table('document_reminder_logs')->orderBy('id')->each(function ($row): void {
            DB::table('document_reminder_logs')->where('id', $row->id)->update([
                'document_owner_type' => CompanyDocument::class,
                'document_owner_id' => $row->company_document_id,
            ]);
        });

        Schema::table('document_reminder_logs', function (Blueprint $table) {
            $table->dropUnique(['company_document_id', 'threshold']);
            $table->dropConstrainedForeignId('company_document_id');
        });

        Schema::table('document_reminder_logs', function (Blueprint $table) {
            $table->string('document_owner_type', 120)->nullable(false)->change();
            $table->unsignedBigInteger('document_owner_id')->nullable(false)->change();

            $table->unique(['document_owner_type', 'document_owner_id', 'threshold']);
            $table->index(['document_owner_type', 'document_owner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('document_reminder_logs', function (Blueprint $table) {
            $table->foreignId('company_document_id')->nullable()->after('id')->constrained('company_documents')->cascadeOnDelete();
        });

        DB::table('document_reminder_logs')
            ->where('document_owner_type', CompanyDocument::class)
            ->orderBy('id')
            ->each(function ($row): void {
                DB::table('document_reminder_logs')->where('id', $row->id)->update([
                    'company_document_id' => $row->document_owner_id,
                ]);
            });

        Schema::table('document_reminder_logs', function (Blueprint $table) {
            $table->dropUnique(['document_owner_type', 'document_owner_id', 'threshold']);
            $table->dropIndex(['document_owner_type', 'document_owner_id']);
            $table->dropColumn(['document_owner_type', 'document_owner_id']);
            $table->unique(['company_document_id', 'threshold']);
        });
    }
};
