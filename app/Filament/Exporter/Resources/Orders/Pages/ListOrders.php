<?php

namespace App\Filament\Exporter\Resources\Orders\Pages;

use App\Filament\Exporter\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
