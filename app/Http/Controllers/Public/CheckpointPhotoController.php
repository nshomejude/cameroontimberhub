<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\ShipmentService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a checkpoint proof photo (private local disk) for web pages.
 *
 * Authorisation is the temporary signed URL itself (30 min, `signed`
 * middleware): it is minted only by ShipmentService::photoSignedUrl() on
 * pages that already authorised the viewer — the buyer order page
 * (BuyerRfqAccess: RFQ owner or signed buyer link, which works for guest
 * buyers too) and the exporter ShipmentResource view (visibleTo()). The
 * shipment + checkpoint ids are covered by the signature, so they cannot be
 * swapped. The public /track/{token} page deliberately never mints one.
 */
class CheckpointPhotoController extends Controller
{
    public function __invoke(ShipmentService $shipments, int $shipment, int $checkpoint): Response
    {
        return $shipments->photoResponse(Shipment::query()->findOrFail($shipment), $checkpoint);
    }
}
