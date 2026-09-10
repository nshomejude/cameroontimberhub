<?php

namespace App\Filament\Exporter\Resources\Drivers\Pages;

use App\Filament\Exporter\Resources\Drivers\DriverResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
