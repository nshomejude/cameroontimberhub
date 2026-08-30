<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Pages;

use App\Filament\Exporter\Resources\CarbonProjects\CarbonProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarbonProject extends EditRecord
{
    protected static string $resource = CarbonProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
