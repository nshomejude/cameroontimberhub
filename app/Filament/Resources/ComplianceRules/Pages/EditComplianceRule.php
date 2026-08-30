<?php

namespace App\Filament\Resources\ComplianceRules\Pages;

use App\Filament\Resources\ComplianceRules\ComplianceRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditComplianceRule extends EditRecord
{
    protected static string $resource = ComplianceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
