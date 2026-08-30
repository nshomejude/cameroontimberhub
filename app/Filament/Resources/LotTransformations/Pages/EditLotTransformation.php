<?php

namespace App\Filament\Resources\LotTransformations\Pages;

use App\Filament\Resources\LotTransformations\LotTransformationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLotTransformation extends EditRecord
{
    protected static string $resource = LotTransformationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
