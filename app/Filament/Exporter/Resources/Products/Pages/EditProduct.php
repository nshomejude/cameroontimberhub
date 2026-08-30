<?php

namespace App\Filament\Exporter\Resources\Products\Pages;

use App\Filament\Exporter\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * company_id belongs to the owning company and is never editable from
     * the form — reassign it back to whatever it already was, regardless of
     * what a crafted request tries to submit.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['company_id'] = $this->record->company_id;

        return $data;
    }
}
