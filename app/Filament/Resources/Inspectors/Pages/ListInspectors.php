<?php

namespace App\Filament\Resources\Inspectors\Pages;

use App\Filament\Resources\Inspectors\InspectorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInspectors extends ListRecords
{
    protected static string $resource = InspectorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
