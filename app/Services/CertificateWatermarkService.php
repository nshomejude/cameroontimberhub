<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Models\Certificate;

/**
 * Ring 3, Layer 15 "variable security background / watermark" (see
 * docs/CERTIFICATE_SPEC.md's Ring 3 bullet), scoped to exactly what a real
 * print supplier relationship is NOT required for: a deterministic,
 * software-only SVG pattern rendered inline in HTML/PDF. This is
 * deliberately a much smaller thing than the physical layer the spec
 * describes and defers as item 0.8b ("Certificate — Physical Production
 * Layer": guilloche/microtext/variable-watermark *print-security*
 * generation, hologram/UV/tamper-seal, physical serial + copy registry) --
 * that item stays out of scope here because it is blocked on a real
 * print/security-label supplier relationship this codebase cannot fake.
 * What this service builds is the two-dimensional, purely-digital analogue:
 * a repeating diagonal background tile seeded from the certificate's own
 * `data_hash` (so two certificates never share a pattern, and the SAME
 * certificate always renders the SAME pattern), carrying microtext of the
 * certificate number tiled inside it. No guilloche engine, no hologram, no
 * UV ink, no security paper -- those remain out of scope per the build
 * instructions.
 *
 * dompdf renders inline SVG data URIs given as a CSS `background-image`
 * just as a browser does, so the same background works unmodified in the
 * printable HTML view (there is no separate PDF template for certificates
 * -- see CertificateVerificationController::show()'s docblock) and in any
 * future dompdf-rendered export.
 */
class CertificateWatermarkService
{
    /**
     * Deterministic seed derived from `data_hash`. Falls back to a fixed
     * seed for an unsigned (data_hash-less) certificate so the page never
     * errors -- it just renders the same pattern every draft gets.
     */
    private function seed(Certificate $certificate): int
    {
        $basis = $certificate->data_hash ?? $certificate->certificate_number ?? 'unsigned';

        return hexdec(substr(hash('sha256', $basis), 0, 8));
    }

    /**
     * CSS custom properties for the repeating background pattern, as an
     * inline `style="..."` attribute value: rotation, opacity and tile size
     * are all derived from the seed, so the pattern is unique per
     * certificate but stable across every render of that same certificate.
     */
    public function backgroundStyle(Certificate $certificate): string
    {
        $seed = $this->seed($certificate);

        $rotation = ($seed % 60) - 30; // -30..29 degrees, a diagonal tilt
        $opacity = round(0.035 + (($seed >> 8) % 5) * 0.008, 3); // 0.035-0.067: visible under light, never fights the printed text
        $tile = 130 + (($seed >> 16) % 6) * 15; // 130-205px tile

        $dataUri = $this->backgroundTileDataUri($certificate, $rotation, $opacity);

        return sprintf(
            'background-image: url(\'%s\'); background-repeat: repeat; background-size: %dpx %dpx;',
            $dataUri,
            $tile,
            $tile
        );
    }

    /**
     * Builds the SVG tile: the certificate number repeated small and faint
     * (microtext, per Ring 3's "microtext" layer) along a diagonal, at the
     * seeded rotation/opacity.
     */
    private function backgroundTileDataUri(Certificate $certificate, int $rotation, float $opacity): string
    {
        $microtext = e($certificate->certificate_number ?? 'CTH');
        $size = 200;

        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $y = 20 + ($i * 50);
            $rows[] = sprintf(
                '<text x="0" y="%d" font-family="monospace" font-size="6" letter-spacing="1" fill="#0a5223">%s</text>',
                $y,
                str_repeat($microtext.'   ', 3)
            );
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d">'
            .'<g transform="rotate(%2$d %3$d %3$d)" opacity="%4$s">%5$s</g>'
            .'</svg>',
            $size,
            $rotation,
            (int) ($size / 2),
            $opacity,
            implode('', $rows)
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The large, unmissable diagonal "VOID" stamp -- distinct from the
     * subtle background pattern above -- shown ONLY for certificates that
     * are no longer the live, trustable document (superseded/revoked), so a
     * printed copy of an outdated certificate can never be mistaken for a
     * currently-valid one even by someone who never checks the QR/URL.
     */
    public function shouldShowVoidStamp(Certificate $certificate): bool
    {
        return in_array($certificate->status, [CertificateStatus::Superseded, CertificateStatus::Revoked], true);
    }

    public function voidLabel(Certificate $certificate): string
    {
        return match ($certificate->status) {
            CertificateStatus::Revoked => 'REVOKED',
            default => 'VOID',
        };
    }
}
