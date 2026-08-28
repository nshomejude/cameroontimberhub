<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single mutation point for a Certificate's canonical data, hash,
 * signature and lifecycle status (gap-plan 0.8, Rings 1+2). Nothing else
 * should call Certificate::update() directly for these fields, so
 * data_hash/signature never drift from the actual `data` payload -- the
 * exact failure mode docs/CERTIFICATE_SPEC.md warns against.
 */
class CertificateService
{
    public function __construct(
        private readonly CertificateHashingService $hasher,
        private readonly CertificateSigningService $signer,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function draft(Model $subject, array $data, float $quantity, string $unit): Certificate
    {
        return Certificate::create([
            'certificate_number' => $this->generateCertificateNumber(),
            'verification_token' => Str::random(48),
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'data' => $data,
            'data_hash' => $this->hasher->hash($data),
            'version' => 1,
            'certified_quantity' => $quantity,
            'quantity_unit' => $unit,
            'status' => CertificateStatus::Draft,
        ]);
    }

    /** Signs the certificate's current data hash and moves it to Verified. */
    public function sign(Certificate $certificate, User $actor): Certificate
    {
        if ($certificate->status !== CertificateStatus::Draft && $certificate->status !== CertificateStatus::UnderReview) {
            throw new RuntimeException("Cannot sign a certificate in status [{$certificate->status->value}].");
        }

        $result = $this->signer->sign($certificate->data_hash);

        return DB::transaction(function () use ($certificate, $result) {
            $certificate->update([
                'key_id' => $result['key_id'],
                'algorithm' => $result['algorithm'],
                'signature' => $result['signature'],
                'signed_at' => now(),
                'approved_at' => now(),
                'status' => CertificateStatus::Verified,
            ]);

            return $certificate->fresh();
        });
    }

    public function issue(Certificate $certificate, User $actor): Certificate
    {
        if ($certificate->status !== CertificateStatus::Verified) {
            throw new RuntimeException('A certificate must be signed and verified before it can be issued. Call sign() first.');
        }

        return DB::transaction(function () use ($certificate) {
            $certificate->update([
                'status' => CertificateStatus::Active,
                'issued_at' => now(),
            ]);

            return $certificate->fresh();
        });
    }

    /**
     * Never edits `data` in place -- creates a new immutable row sharing
     * `certificate_number`, hashes it and requires re-signing, and marks the
     * prior row Superseded.
     *
     * The prior row is superseded BEFORE the successor is inserted, not
     * after: `certificates_one_live_version_idx` is a partial UNIQUE index
     * that Postgres evaluates per statement, so inserting a live successor
     * while the predecessor is still live would violate it.
     *
     * @param  array<string, mixed>  $newData
     */
    public function createVersion(Certificate $current, array $newData, string $reason, User $actor): Certificate
    {
        if (blank($reason)) {
            throw new RuntimeException('A reason is required to create a new certificate version.');
        }

        return DB::transaction(function () use ($current, $newData, $reason, $actor) {
            $current->update(['status' => CertificateStatus::Superseded]);

            $next = Certificate::create([
                'certificate_number' => $current->certificate_number,
                'verification_token' => Str::random(48),
                'subject_type' => $current->subject_type,
                'subject_id' => $current->subject_id,
                'data' => $newData,
                'data_hash' => $this->hasher->hash($newData),
                'version' => $current->version + 1,
                'previous_version_id' => $current->id,
                'version_reason' => $reason,
                'version_actor_id' => $actor->getKey(),
                'certified_quantity' => $current->certified_quantity,
                'quantity_unit' => $current->quantity_unit,
                'status' => CertificateStatus::Draft,
            ]);

            return $next->fresh();
        });
    }

    /** e.g. TH-CMR-ORG-2026-A1B2C3D4E5 -- high-entropy suffix, never the DB primary key. */
    private function generateCertificateNumber(): string
    {
        return sprintf('TH-CMR-ORG-%d-%s', now()->year, strtoupper(Str::random(10)));
    }
}
