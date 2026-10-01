<?php

namespace App\Filament\Resources\CommissionStatements\Pages;

use App\Filament\Resources\CommissionStatements\CommissionStatementResource;
use Filament\Resources\Pages\ListRecords;

class ListCommissionStatements extends ListRecords
{
    protected static string $resource = CommissionStatementResource::class;
}
