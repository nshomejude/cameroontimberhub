<?php

namespace App\Filament\Exporter\Resources\ApiUsages\Pages;

use App\Filament\Exporter\Resources\ApiUsages\ApiUsageResource;
use Filament\Resources\Pages\ListRecords;

class ListApiUsages extends ListRecords
{
    protected static string $resource = ApiUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
