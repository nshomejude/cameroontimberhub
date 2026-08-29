<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\ShipmentWaybillQrCodeService;
use Illuminate\View\View;

/**
 * Public digital waybill page (gap-plan 1.5.10). No auth — a printed/scanned
 * waybill has to work for a checkpoint officer or receiving clerk with no
 * account. Looked up by the unguessable `waybill_number` token, never the id.
 */
class ShipmentWaybillController extends Controller
{
    public function show(Shipment $shipment, ShipmentWaybillQrCodeService $qr): View
    {
        return view('public.shipments.waybill', [
            'shipment' => $shipment,
            'qrDataUri' => $qr->dataUri($shipment),
            'cargoProductIds' => $shipment->cargoProductIds(),
        ]);
    }
}
