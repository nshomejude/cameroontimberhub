<?php

namespace App\Filament\Exporter\Resources\Drivers\Pages;

use App\Filament\Exporter\Resources\Drivers\DriverResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = auth()->user()->companies()->firstOrFail()->getKey();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
