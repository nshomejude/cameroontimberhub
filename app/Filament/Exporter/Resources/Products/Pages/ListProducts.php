<?php

namespace App\Filament\Exporter\Resources\Products\Pages;

use App\Filament\Exporter\Resources\Products\Pages\Concerns\ShowsPublicVisibilityBanner;
use App\Filament\Exporter\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    use ShowsPublicVisibilityBanner;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
