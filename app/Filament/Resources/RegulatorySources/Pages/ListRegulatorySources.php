<?php

namespace App\Filament\Resources\RegulatorySources\Pages;

use App\Filament\Resources\RegulatorySources\RegulatorySourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegulatorySources extends ListRecords
{
    protected static string $resource = RegulatorySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
