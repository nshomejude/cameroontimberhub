<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\DocumentVerificationStatus;
use App\Models\CompanyDocument;
use App\Models\Document;
use App\Models\OrderDocument;
use Illuminate\Console\Command;

/**
 * Idempotent, order-preserving copy of every CompanyDocument/OrderDocument
 * row into the polymorphic `documents` table (gap-plan item 0.1b).
 *
 * Does NOT batch-insert: each row is created individually through Eloquent,
 * in (created_at, id) ASC order per owner, so Document::booted()'s
 * creating hook actually runs and the per-owner hash chain builds
 * correctly. Safe to re-run -- an already-backfilled legacy row is skipped
 * by matching (owner_type, owner_id, storage_path, created_at), which is
 * unique enough given storage_path always embeds a UUID.
 */
class BackfillDocumentsFromLegacyTables extends Command
{
    protected $signature = 'documents:backfill-from-legacy';

    protected $description = 'Copy every CompanyDocument/OrderDocument row into the polymorphic documents table, preserving upload order.';

    public function handle(): int
    {
        $companyCount = $this->backfillCompanyDocuments();
        $orderCount = $this->backfillOrderDocuments();

        $this->info("Backfilled {$companyCount} CompanyDocument row(s) and {$orderCount} OrderDocument row(s) into documents.");

        return self::SUCCESS;
    }

    private function backfillCompanyDocuments(): int
    {
        $count = 0;

        CompanyDocument::withoutGlobalScopes()
            ->with('documentType')
            ->orderBy('company_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunkById(200, function ($legacyDocs) use (&$count): void {
                foreach ($legacyDocs as $legacy) {
                    if ($this->alreadyBackfilled('App\\Models\\Company', $legacy->company_id, $legacy)) {
                        continue;
                    }

                    Document::create([
                        'owner_type' => 'App\\Models\\Company',
                        'owner_id' => $legacy->company_id,
                        'type' => $legacy->documentType?->key ?? 'company_document',
                        'document_type_id' => $legacy->document_type_id,
                        'original_filename' => $legacy->original_filename,
                        'disk' => $legacy->disk,
                        'storage_path' => $legacy->storage_path,
                        'mime_type' => $legacy->mime_type,
                        'file_size' => $legacy->file_size,
                        'checksum_sha256' => $legacy->checksum_sha256,
                        'issuer' => null,
                        'issued_at' => $legacy->issue_date,
                        'expires_at' => $legacy->expiry_date,
                        'verification_status' => $this->mapStatus($legacy->status)->value,
                        'visibility' => $legacy->visibility?->value,
                        'sigif_fields' => $legacy->sigif_fields,
                        'review_notes' => $legacy->review_notes,
                        'reviewed_by' => $legacy->reviewed_by,
                        'reviewed_at' => $legacy->reviewed_at,
                        'uploaded_by' => $legacy->uploaded_by,
                        'created_at' => $legacy->created_at,
                        'updated_at' => $legacy->updated_at,
                    ]);

                    $count++;
                }
            });

        return $count;
    }

    private function backfillOrderDocuments(): int
    {
        $count = 0;

        OrderDocument::query()
            ->orderBy('order_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunkById(200, function ($legacyDocs) use (&$count): void {
                foreach ($legacyDocs as $legacy) {
                    if ($this->alreadyBackfilled('App\\Models\\Order', $legacy->order_id, $legacy)) {
                        continue;
                    }

                    Document::create([
                        'owner_type' => 'App\\Models\\Order',
                        'owner_id' => $legacy->order_id,
                        'type' => $legacy->kind?->value ?? 'other',
                        'original_filename' => $legacy->original_filename,
                        'disk' => $legacy->disk,
                        'storage_path' => $legacy->storage_path,
                        'mime_type' => $legacy->mime_type,
                        'file_size' => $legacy->size_bytes,
                        'checksum_sha256' => $legacy->checksum,
                        'verification_status' => DocumentVerificationStatus::Unverified->value,
                        'uploaded_by' => $legacy->uploaded_by_user_id,
                        'created_at' => $legacy->created_at,
                        'updated_at' => $legacy->updated_at,
                    ]);

                    $count++;
                }
            });

        return $count;
    }

    private function alreadyBackfilled(string $ownerType, int $ownerId, object $legacy): bool
    {
        return Document::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('storage_path', $legacy->storage_path)
            ->where('created_at', $legacy->created_at)
            ->exists();
    }

    private function mapStatus(DocumentStatus $status): DocumentVerificationStatus
    {
        return match ($status) {
            DocumentStatus::Pending => DocumentVerificationStatus::Unverified,
            DocumentStatus::Approved => DocumentVerificationStatus::Verified,
            DocumentStatus::Rejected => DocumentVerificationStatus::Rejected,
            DocumentStatus::NeedsCorrection => DocumentVerificationStatus::NeedsCorrection,
        };
    }
}
