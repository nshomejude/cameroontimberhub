<?php

namespace App\Services;

use App\Models\Shipment;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Dynamic QR pointing at the shipment's live public waybill page — same
 * "point at a URL, not a snapshot" pattern as CertificateQrCodeService. The
 * page it points to lists the cargo's product IDs, satisfying gap-plan
 * 1.5.10's "QR linking cargo product IDs" without embedding a data blob that
 * would go stale if the order's items ever changed.
 */
class ShipmentWaybillQrCodeService
{
    public function waybillUrl(Shipment $shipment): string
    {
        return route('shipments.waybill.show', $shipment);
    }

    /** Returns an inline `data:image/svg+xml;base64,...` string, safe to drop straight into an <img src>. */
    public function dataUri(Shipment $shipment): string
    {
        return (new Builder(
            writer: new SvgWriter,
            data: $this->waybillUrl($shipment),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 240,
            margin: 8,
        ))->build()->getDataUri();
    }
}
