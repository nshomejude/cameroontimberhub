<?php

namespace App\Filament\Exporter\Resources\CompanySpecies\Pages;

use App\Filament\Exporter\Resources\CompanySpecies\CompanySpeciesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompanySpecies extends EditRecord
{
    protected static string $resource = CompanySpeciesResource::class;

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
