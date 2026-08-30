<?php

namespace App\Filament\Exporter\Resources\Capacities\Pages;

use App\Filament\Exporter\Resources\Capacities\CapacityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCapacity extends EditRecord
{
    protected static string $resource = CapacityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
