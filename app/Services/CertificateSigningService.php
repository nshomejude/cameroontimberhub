<?php

namespace App\Services;

use RuntimeException;

/**
 * Real asymmetric signing over a certificate's canonical data hash
 * (docs/CERTIFICATE_SPEC.md Ring 1, Layers 5-6), using PHP 8.3's built-in
 * `sodium` extension (Ed25519 via sodium_crypto_sign_detached) -- no
 * external crypto library needed.
 *
 * IMPORTANT, stated plainly per this project's rule against fabricating
 * infrastructure: this IS a genuine cryptographic signature -- a
 * certificate's signature cannot be forged without the private key file,
 * and it cryptographically binds a specific key_id to a specific data_hash.
 * It is NOT backed by a KMS or HSM. The private key currently lives in a
 * file on the application server (config('certificates.signing_key_path')),
 * separated from the database (an app admin browsing Filament cannot read
 * it) but not separated from the application server itself. Upgrading to a
 * real KMS/HSM -- where the application asks a signing service to sign and
 * never touches the private key at all (the spec's Ring 1 Layer 6 wording)
 * -- is documented future work, tracked in docs/GAP_PLAN.md. It is not
 * pretended to already exist.
 */
class CertificateSigningService
{
    /**
     * Generates a fresh Ed25519 keypair and writes it to the configured
     * path. Overwrites any existing key -- callers must not call this
     * against a production key path without an explicit rotation decision.
     */
    public function generateKeypair(): void
    {
        $keypair = sodium_crypto_sign_keypair();

        $payload = [
            'key_id' => config('certificates.key_id'),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'generated_at' => now()->toIso8601String(),
        ];

        $path = config('certificates.signing_key_path');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, recursive: true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }

    /** @return array{key_id: string, algorithm: string, signature: string} */
    public function sign(string $hash): array
    {
        $key = $this->loadKey();

        $signature = sodium_crypto_sign_detached($hash, base64_decode($key['private_key']));

        return [
            'key_id' => $key['key_id'],
            'algorithm' => 'ed25519',
            'signature' => base64_encode($signature),
        ];
    }

    /**
     * True only for a signature this keypair genuinely produced over exactly
     * this hash.
     *
     * A malformed or wrong-length signature is a failed verification, not an
     * exception: callers are verifying attacker-supplied or legacy data (the
     * public verification page most of all), and a 500 there would be both a
     * worse answer and an information leak. A MISSING key file still throws,
     * because that is a server misconfiguration rather than a bad signature.
     */
    public function verify(string $hash, string $signatureBase64): bool
    {
        $key = $this->loadKey();

        $signature = base64_decode($signatureBase64, true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $hash, base64_decode($key['public_key']));
    }

    /** @return array{key_id: string, private_key: string, public_key: string} */
    private function loadKey(): array
    {
        $path = config('certificates.signing_key_path');

        if (! file_exists($path)) {
            throw new RuntimeException("Certificate signing key not found at [{$path}]. Run: php artisan certificates:generate-signing-key");
        }

        return json_decode(file_get_contents($path), true);
    }
}
