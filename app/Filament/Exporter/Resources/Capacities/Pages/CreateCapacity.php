<?php

namespace App\Filament\Exporter\Resources\Capacities\Pages;

use App\Filament\Exporter\Resources\Capacities\CapacityResource;
use App\Models\Company;
use Filament\Resources\Pages\CreateRecord;

class CreateCapacity extends CreateRecord
{
    protected static string $resource = CapacityResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();

        $data['owner_type'] = Company::class;
        $data['owner_id'] = $company->getKey();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
