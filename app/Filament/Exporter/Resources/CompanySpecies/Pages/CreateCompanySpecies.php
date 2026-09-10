<?php

namespace App\Filament\Exporter\Resources\CompanySpecies\Pages;

use App\Filament\Exporter\Resources\CompanySpecies\CompanySpeciesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanySpecies extends CreateRecord
{
    protected static string $resource = CompanySpeciesResource::class;

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
