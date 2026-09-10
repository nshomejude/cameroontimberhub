<?php

namespace App\Services;

use App\Models\Product;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Dynamic QR pointing at the listing's live public verification URL
 * (gap-plan §1.1) -- never embeds the listing, only the public-id URL, so a
 * scanner always sees the product's CURRENT verification state rather than a
 * snapshot frozen at print time.
 *
 * SVG rather than PNG: needs no GD/Imagick, stays sharp at any print
 * resolution, and inlines as a data URI without a binary blob. High error
 * correction so a scuffed printed code still scans.
 *
 * Mirrors CertificateQrCodeService / ShipmentWaybillQrCodeService, including
 * endroid/qr-code v6's constructor Builder API.
 */
class ProductQrCodeService
{
    public function verificationUrl(Product $product): string
    {
        return route('products.verify', $product->public_id);
    }

    /** Raw `<svg>…</svg>` markup, safe to inline directly into a page. */
    public function svg(Product $product): string
    {
        return $this->builder($product)->build()->getString();
    }

    /** Inline `data:image/svg+xml;base64,…` string, safe to drop into an <img src>. */
    public function dataUri(Product $product): string
    {
        return $this->builder($product)->build()->getDataUri();
    }

    private function builder(Product $product): Builder
    {
        return new Builder(
            writer: new SvgWriter,
            data: $this->verificationUrl($product),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 240,
            margin: 8,
        );
    }
}
