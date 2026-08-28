<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Document;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Evidence manifest integrity (docs/CERTIFICATE_SPEC.md Ring 2, Layer 12):
 * every evidence file is individually SHA-256 hashed
 * (Document::checksum_sha256, populated for real as of gap-plan 0.8),
 * aggregated here into ONE manifest hash bound to a Certificate.
 */
class CertificateEvidenceService
{
    /**
     * Sorts checksums before joining so the manifest hash is independent of
     * the order documents happen to be passed in -- an evidence set is a
     * SET, not a sequence.
     *
     * @param  Collection<int, Document>  $documents
     */
    public function manifestHash(Collection $documents): string
    {
        $checksums = $documents->map(function (Document $document) {
            if ($document->checksum_sha256 === null) {
                throw new RuntimeException("Document [{$document->id}] has no checksum_sha256 yet -- cannot include it in an evidence manifest.");
            }

            return $document->checksum_sha256;
        })->sort()->values()->all();

        return hash('sha256', implode('|', $checksums));
    }

    /** @param  Collection<int, Document>  $documents */
    public function attachEvidence(Certificate $certificate, Collection $documents): Certificate
    {
        $certificate->update([
            'evidence_manifest_hash' => $this->manifestHash($documents),
        ]);

        return $certificate->fresh();
    }
}
