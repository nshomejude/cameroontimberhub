<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Pages;

use App\Filament\Exporter\Resources\CarbonProjects\CarbonProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarbonProject extends CreateRecord
{
    protected static string $resource = CarbonProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();

        $data['company_id'] = $company->getKey();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
