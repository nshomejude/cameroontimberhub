<?php

namespace App\Filament\Resources\Inspectors\Pages;

use App\Filament\Resources\Inspectors\InspectorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInspector extends EditRecord
{
    protected static string $resource = InspectorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
