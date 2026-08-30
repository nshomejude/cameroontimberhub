<?php

namespace App\Filament\Exporter\Resources\Capacities\Pages;

use App\Filament\Exporter\Resources\Capacities\CapacityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCapacities extends ListRecords
{
    protected static string $resource = CapacityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
