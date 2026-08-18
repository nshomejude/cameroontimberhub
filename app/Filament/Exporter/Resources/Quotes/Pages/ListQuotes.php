<?php

namespace App\Filament\Exporter\Resources\Quotes\Pages;

use App\Filament\Exporter\Resources\Quotes\QuoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New quote'),
        ];
    }
}
