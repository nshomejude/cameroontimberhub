<?php

namespace App\Services;

use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Renders a Code 128 barcode for the same short human-readable identifier
 * that the entity's QR code already encodes (certificate_number,
 * lot_number, waybill_number, public_id -- see the callers of this
 * service) -- never a second, different identifier. The QR code points at
 * a live verification URL (see CertificateQrCodeService's docblock); the
 * barcode instead encodes the short printed identifier itself, because
 * that is what a handheld barcode scanner at a warehouse/customs desk
 * actually needs to key back into TimberHub's systems without typing it in.
 *
 * picqer/php-barcode-generator's SVG generator was chosen for the same
 * reason endroid/qr-code's SvgWriter was chosen for the QR codes (see
 * CertificateQrCodeService's docblock): no GD/Imagick extension required,
 * stays sharp at any print resolution, and inlines as a data URI without a
 * binary blob.
 */
class BarcodeService
{
    /** Returns an inline `data:image/svg+xml;base64,...` string, safe to drop straight into an <img src>. */
    public function svgDataUri(string $value): string
    {
        $generator = new BarcodeGeneratorSVG;

        $svg = $generator->getBarcode($value, $generator::TYPE_CODE_128, 2, 60);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
