<?php

namespace App\Filament\Resources\TransformationRequests\Pages;

use App\Filament\Resources\TransformationRequests\TransformationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTransformationRequests extends ListRecords
{
    protected static string $resource = TransformationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
