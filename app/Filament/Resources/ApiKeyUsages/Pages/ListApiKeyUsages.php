<?php

namespace App\Filament\Resources\ApiKeyUsages\Pages;

use App\Filament\Resources\ApiKeyUsages\ApiKeyUsageResource;
use Filament\Resources\Pages\ListRecords;

class ListApiKeyUsages extends ListRecords
{
    protected static string $resource = ApiKeyUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
