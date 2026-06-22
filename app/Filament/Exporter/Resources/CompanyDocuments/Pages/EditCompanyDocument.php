<?php

namespace App\Filament\Exporter\Resources\CompanyDocuments\Pages;

use App\Filament\Exporter\Resources\CompanyDocuments\CompanyDocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCompanyDocument extends EditRecord
{
    protected static string $resource = CompanyDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
