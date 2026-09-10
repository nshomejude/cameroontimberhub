<?php

namespace App\Filament\Exporter\Resources\Drivers\Pages;

use App\Filament\Exporter\Resources\Drivers\DriverResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
