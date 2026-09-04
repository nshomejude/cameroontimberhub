<?php

namespace App\Filament\Exporter\Resources\InspectionRequests\Pages;

use App\Filament\Exporter\Resources\InspectionRequests\InspectionRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInspectionRequests extends ListRecords
{
    protected static string $resource = InspectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Request Inspection'),
        ];
    }
}
