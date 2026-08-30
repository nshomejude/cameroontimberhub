<?php

namespace App\Filament\Resources\LotTransformations\Pages;

use App\Filament\Resources\LotTransformations\LotTransformationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLotTransformations extends ListRecords
{
    protected static string $resource = LotTransformationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
