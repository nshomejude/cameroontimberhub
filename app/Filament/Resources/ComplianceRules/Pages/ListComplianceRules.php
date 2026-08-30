<?php

namespace App\Filament\Resources\ComplianceRules\Pages;

use App\Filament\Resources\ComplianceRules\ComplianceRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComplianceRules extends ListRecords
{
    protected static string $resource = ComplianceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
