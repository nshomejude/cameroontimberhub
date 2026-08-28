<?php

namespace App\Services;

use App\Models\Certificate;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Dynamic QR pointing at the live verification URL
 * (docs/CERTIFICATE_SPEC.md Ring 2, Layer 8) -- never embeds the full
 * certificate, only the token URL, so what a scanner sees is always the
 * certificate's CURRENT status rather than a snapshot frozen at print time.
 *
 * SVG rather than PNG: it needs no GD/Imagick extension, stays sharp at any
 * print resolution, and inlines as a data URI without a binary blob.
 * High error correction so a scuffed or partly obscured printed code still
 * scans.
 *
 * Uses endroid/qr-code v6's constructor API (the fluent Builder::create()
 * chaining from v4 no longer exists).
 */
class CertificateQrCodeService
{
    public function verificationUrl(Certificate $certificate): string
    {
        return route('certificates.verify.token', $certificate->verification_token);
    }

    /** Returns an inline `data:image/svg+xml;base64,...` string, safe to drop straight into an <img src>. */
    public function dataUri(Certificate $certificate): string
    {
        return (new Builder(
            writer: new SvgWriter,
            data: $this->verificationUrl($certificate),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 240,
            margin: 8,
        ))->build()->getDataUri();
    }
}
