<?php

return [
    /*
     * Path to the JSON file holding the certificate signing keypair
     * (docs/CERTIFICATE_SPEC.md Ring 1, Layer 6). Deliberately NOT a
     * database column -- see CertificateSigningService's docblock for why.
     * Must live outside the web root and outside version control. The
     * default path keeps it inside Laravel's own storage tree, which is
     * already excluded from the web server's document root.
     */
    'signing_key_path' => env('CERTIFICATE_SIGNING_KEY_PATH', storage_path('app/certificates/signing-key.json')),

    /*
     * Identifies which keypair signed a given certificate row
     * (certificates.key_id) -- lets a future key rotation keep old
     * signatures verifiable against the key that actually made them,
     * without needing to know "the" current key.
     */
    'key_id' => env('CERTIFICATE_SIGNING_KEY_ID', 'timberhub-cert-key-1'),
];
