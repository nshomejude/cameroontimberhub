<?php

namespace App\Filament\Exporter\Resources\Shipments\Pages;

use App\Filament\Exporter\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\ListRecords;

class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;
}
