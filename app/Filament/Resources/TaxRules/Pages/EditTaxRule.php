<?php

namespace App\Filament\Resources\TaxRules\Pages;

use App\Filament\Resources\TaxRules\TaxRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxRule extends EditRecord
{
    protected static string $resource = TaxRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
