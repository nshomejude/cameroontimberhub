<?php

namespace App\Models;

use App\Enums\OrderDocumentKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to an order — proof of delivery or a shipping paper.
 *
 * The row records where the bytes live; it never exposes them. There is no
 * public URL accessor on purpose: the ONLY way to the file is
 * OrderDocumentDownloadController, which re-derives participation from the
 * order rather than trusting anything in the request.
 */
class OrderDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => OrderDocumentKind::class,
            'size_bytes' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** What the supplier called it, falling back to the uploaded filename. */
    public function displayName(): string
    {
        return $this->label ?: $this->original_filename;
    }

    /** "245 KB" — the real byte count, never a placeholder. */
    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    /** Short uppercase extension for the file-type chip. */
    public function extension(): string
    {
        return mb_strtoupper(pathinfo((string) $this->original_filename, PATHINFO_EXTENSION)) ?: 'FILE';
    }

    public function isProofOfDelivery(): bool
    {
        return $this->kind === OrderDocumentKind::ProofOfDelivery;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
