<?php

namespace App\Services;

use App\Models\Certificate;
use RuntimeException;

/**
 * The public certificate-verification boundary, mirroring
 * app/Services/ReceiptVerifier.php's shape: lookup by unguessable token, a
 * hand-written allow-list payload, and a "not found" that reads identically
 * for every failure reason so the endpoint cannot be used as an oracle
 * (docs/CERTIFICATE_SPEC.md's "Recommended Verification Result").
 *
 * A superseded or revoked certificate still RESOLVES -- deliberately. Telling
 * a holder "this document is real, but it is no longer the current version"
 * is the single most useful thing this page can say, and is a different
 * question from whether the token exists at all.
 */
class CertificateVerifier
{
    public function __construct(
        private readonly CertificateSigningService $signer,
    ) {}

    public function findByToken(string $token): ?Certificate
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return Certificate::with('subject')->where('verification_token', $token)->first();
    }

    /** @return array<string, mixed> */
    public function publicPayload(Certificate $certificate): array
    {
        $liveVersion = Certificate::query()
            ->where('certificate_number', $certificate->certificate_number)
            ->liveVersion()
            ->orderByDesc('version')
            ->first();

        return [
            'issuer' => config('app.name'),
            'certificate_number' => $certificate->certificate_number,
            'found' => true,
            'signature_valid' => $this->signatureIsValid($certificate),
            'hash_valid' => $certificate->data_hash !== null,
            'version' => $certificate->version,
            'is_current_version' => $liveVersion?->id === $certificate->id,
            'status' => $certificate->status->label(),
            'is_currently_valid' => $certificate->status->isCurrentlyValid(),
            'data' => $certificate->data,
            'fingerprint' => $certificate->fingerprint(),
            'evidence_status' => $certificate->evidence_manifest_hash !== null ? 'Evidence manifest bound' : 'No evidence manifest recorded',
            'geospatial_status' => $certificate->geospatial_hash !== null ? 'Geospatial record bound' : 'No geospatial record',
            'issued_at' => $certificate->issued_at,
            'checked_at' => now(),
        ];
    }

    /**
     * A signature that cannot be checked is reported as not-valid, never as
     * an error page. This endpoint is public and unauthenticated, so a
     * missing signing key or a certificate signed by a retired key must
     * degrade to "could not verify" rather than a 500 that leaks server
     * state.
     */
    private function signatureIsValid(Certificate $certificate): bool
    {
        if ($certificate->signature === null || $certificate->data_hash === null) {
            return false;
        }

        try {
            return $this->signer->verify($certificate->data_hash, $certificate->signature);
        } catch (RuntimeException) {
            return false;
        }
    }
}
