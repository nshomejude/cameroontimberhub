<?php

namespace App\Console\Commands;

use App\Services\CertificateSigningService;
use Illuminate\Console\Command;

/**
 * Generates the application's certificate signing keypair (see
 * CertificateSigningService's docblock for what this is and is not).
 * Run once per environment during setup, and again on a deliberate key
 * rotation -- never as part of an automated deploy step, since it
 * overwrites the existing key file.
 */
class GenerateCertificateSigningKey extends Command
{
    protected $signature = 'certificates:generate-signing-key {--force : Overwrite an existing key without confirmation}';

    protected $description = 'Generate the Ed25519 keypair used to sign certificates';

    public function handle(CertificateSigningService $signer): int
    {
        $path = config('certificates.signing_key_path');

        if (file_exists($path) && ! $this->option('force')) {
            if (! $this->confirm("A signing key already exists at [{$path}]. Overwriting it will invalidate verification of any signature made with the old key. Continue?")) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        $signer->generateKeypair();

        $this->info("Certificate signing key generated at [{$path}].");

        return self::SUCCESS;
    }
}
