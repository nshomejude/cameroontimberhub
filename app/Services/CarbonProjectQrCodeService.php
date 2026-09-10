<?php

namespace App\Services;

use App\Models\CarbonProject;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Dynamic QR pointing at a carbon project's live public verification URL
 * (§2.6) -- never embeds the project, only the public-id URL, so a scanner
 * always sees the project's CURRENT registry state rather than a snapshot.
 *
 * Mirrors CertificateQrCodeService / ProductQrCodeService, including
 * endroid/qr-code v6's constructor Builder API.
 */
class CarbonProjectQrCodeService
{
    public function verificationUrl(CarbonProject $project): string
    {
        return route('carbon.verify', $project->public_id);
    }

    /** Raw `<svg>…</svg>` markup, safe to inline directly into a page. */
    public function svg(CarbonProject $project): string
    {
        return $this->builder($project)->build()->getString();
    }

    /** Inline `data:image/svg+xml;base64,…` string, safe to drop into an <img src>. */
    public function dataUri(CarbonProject $project): string
    {
        return $this->builder($project)->build()->getDataUri();
    }

    private function builder(CarbonProject $project): Builder
    {
        return new Builder(
            writer: new SvgWriter,
            data: $this->verificationUrl($project),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 240,
            margin: 8,
        );
    }
}
