<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Pages;

use App\Filament\Exporter\Resources\CarbonProjects\CarbonProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarbonProjects extends ListRecords
{
    protected static string $resource = CarbonProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
