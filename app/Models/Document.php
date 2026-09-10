<?php

namespace App\Models;

use App\Enums\DocumentVerificationStatus;
use App\Enums\DocumentVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to any owning entity (Species today; carbon projects,
 * sponsorship files, vehicles and drivers as those land) with expiry and a
 * verification workflow.
 *
 * Files live on the private `documents` disk (see config/filesystems.php,
 * the same disk `CompanyDocument`/`OrderDocument` already use) and are never
 * web-served directly. That disk is a local/private driver with no signed-URL
 * support (no `temporaryUrl()`), so this model deliberately does NOT expose a
 * `url()` accessor -- reading the bytes back requires a dedicated download
 * controller that re-derives access from the owner, following the pattern
 * OrderDocumentDownloadController already establishes. No such controller
 * exists yet for this table; build one when a real owner that needs gated
 * access (rather than Species, which is public) is added.
 *
 * hash/prev_hash chain per-owner in upload order (see booted(), which fires
 * on create, matching how a receipt or order-event log would chain). This
 * gives §3.9 of the brief its hash-chain primitive; do not re-derive it in
 * gap-plan item 0.5 -- extend this.
 *
 * hash/prev_hash are NOT the uploaded file's checksum -- they hash row
 * metadata (owner, filename, storage path, previous hash, timestamp) purely
 * to chain rows in per-owner upload order. The file's own byte-for-byte
 * integrity is tracked separately in `checksum_sha256`, computed from the
 * actual file bytes on the configured disk at creation time (see booted()).
 *
 * `document_type_id`/`visibility`/`sigif_fields` were added additively
 * (2026_08_29_100020_add_company_document_fields_to_documents_table.php)
 * specifically to support gap-plan item 0.1b -- migrating CompanyDocument's
 * consumers onto this table without losing the three fields CompanyDocument
 * had that this table originally didn't. All three are nullable: no other
 * owner type (Species, Product) is expected to populate them.
 */
class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = [
        'verification_status' => 'unverified',
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => DocumentVerificationStatus::class,
            'visibility' => DocumentVisibility::class,
            'sigif_fields' => 'array',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Document $document): void {
            // NOT ATOMIC: this is a read-then-write (find latest row for this
            // owner, then chain off its hash) with no locking. Two documents
            // created concurrently for the same owner (e.g. a future queued
            // bulk import) can both read the same "latest" row before either
            // commits, producing two rows with the same prev_hash and
            // breaking the chain. Fine for today's single synchronous
            // Filament form submission at a time, but a real fix needs
            // either lockForUpdate() inside a transaction here, or a DB-level
            // unique constraint on (owner_type, owner_id, prev_hash).
            $previous = static::withoutGlobalScopes()
                ->where('owner_type', $document->owner_type)
                ->where('owner_id', $document->owner_id)
                ->latest('id')
                ->first();

            $document->prev_hash = $previous?->hash;
            $document->hash = hash('sha256', implode('|', [
                $document->owner_type,
                $document->owner_id,
                $document->original_filename,
                $document->storage_path,
                $document->prev_hash ?? '',
                now()->toISOString(),
            ]));

            // First real population of checksum_sha256 -- see this model's
            // own class docblock, which until now documented that nothing
            // populated it. This is the file's own byte-for-byte integrity
            // hash (distinct from hash/prev_hash above, which chain row
            // METADATA in upload order, not file contents). Left null,
            // never fabricated, if the file genuinely cannot be read yet
            // (e.g. a row created before its file finished uploading).
            if ($document->checksum_sha256 === null && $document->disk && $document->storage_path) {
                $disk = Storage::disk($document->disk);

                if ($disk->exists($document->storage_path)) {
                    $document->checksum_sha256 = hash('sha256', (string) $disk->get($document->storage_path));

                    // Fill the file metadata a bare FileUpload doesn't capture
                    // (it only writes storage_path + original_filename). Never
                    // fabricated — only set from the real file on disk.
                    if ($document->file_size === null) {
                        $document->file_size = $disk->size($document->storage_path);
                    }

                    if (blank($document->mime_type)) {
                        $document->mime_type = $disk->mimeType($document->storage_path) ?: 'application/octet-stream';
                    }
                }
            }
        });
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeForOwner(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner::class)->where('owner_id', $owner->getKey());
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', DocumentVerificationStatus::Verified->value);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A human label for the free-text `type` column. Known per-product
     * document types get a friendly name; anything else is title-cased.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'datasheet' => 'Product datasheet',
            'legal_origin' => 'Proof of legal origin',
            'fsc_pefc' => 'FSC / PEFC certificate',
            'phytosanitary' => 'Phytosanitary certificate',
            'other' => 'Other document',
            default => (string) str((string) $this->type)->replace(['_', '-'], ' ')->headline(),
        };
    }

    /**
     * Trust state for a public listing: 'expired' | 'verified' | 'pending'.
     * Expiry wins over verification — an expired doc is not trustworthy even
     * once staff-verified.
     */
    public function publicStatus(): string
    {
        if ($this->isExpired()) {
            return 'expired';
        }

        return $this->verification_status === DocumentVerificationStatus::Verified ? 'verified' : 'pending';
    }
}
