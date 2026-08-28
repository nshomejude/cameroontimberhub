<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One immutable version of a TimberHub certificate (gap-plan 0.8, Rings 1+2
 * of docs/CERTIFICATE_SPEC.md). `certificate_number` is the public identity,
 * shared across every version; `id` is never exposed publicly.
 * `verification_token` is a separate, high-entropy per-row secret -- see the
 * migration comment and CertificateVerifier for how a lookup by token differs
 * from a lookup by number.
 *
 * Mutation of `data`/`status`/signature fields should go through
 * CertificateService and its collaborators (CertificateHashingService,
 * CertificateSigningService), never direct assignment here -- those services
 * are the single point that keeps data_hash/signature honest against the
 * actual `data` payload.
 */
class Certificate extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = ['id'];

    /**
     * The immutable audit trail (docs/CERTIFICATE_SPEC.md Ring 2, Layer 10).
     * Only the integrity-bearing attributes are logged -- a change to any of
     * them is exactly what an auditor needs to see. CertificateService adds
     * named events ('signed', 'issued', 'version_created', 'superseded')
     * carrying the acting user as causer, which attribute diffs alone cannot
     * express.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'version', 'data_hash', 'signature', 'evidence_manifest_hash', 'geospatial_hash'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'data' => 'array',
            'geospatial_data' => 'array',
            'certified_quantity' => 'decimal:3',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function nextVersions(): HasMany
    {
        return $this->hasMany(self::class, 'previous_version_id');
    }

    public function versionActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'version_actor_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CertificateAllocation::class);
    }

    public function scopeForNumber(Builder $query, string $certificateNumber): Builder
    {
        return $query->where('certificate_number', $certificateNumber);
    }

    /** The one row for a given certificate_number that is not superseded/replaced. */
    public function scopeLiveVersion(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            CertificateStatus::Superseded->value,
            CertificateStatus::Replaced->value,
        ]);
    }

    /** A short, human-readable slice of the hash for print display (spec Layer 10, "Cryptographic Fingerprint"). */
    public function fingerprint(): ?string
    {
        return $this->data_hash === null
            ? null
            : strtoupper(implode(' ', str_split(substr($this->data_hash, 0, 16), 4)));
    }
}
