<?php

namespace App\Filament\Exporter\Resources\Vehicles\Pages;

use App\Filament\Exporter\Resources\Vehicles\VehicleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVehicle extends CreateRecord
{
    protected static string $resource = VehicleResource::class;

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
