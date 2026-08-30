<?php

namespace App\Filament\Exporter\Resources\Products\Pages;

use App\Filament\Exporter\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Ownership is decided server-side — the form never supplies company_id,
     * even if a crafted request tries to smuggle one in.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();

        $data['company_id'] = $company->getKey();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
