<?php

namespace App\Filament\Resources\RegulatorySources\Pages;

use App\Filament\Resources\RegulatorySources\RegulatorySourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegulatorySource extends EditRecord
{
    protected static string $resource = RegulatorySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
