<?php

namespace App\Filament\Resources\GlossaryTerms\Pages;

use App\Filament\Resources\GlossaryTerms\GlossaryTermResource;
use App\Models\GlossaryTerm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGlossaryTerm extends EditRecord
{
    protected static string $resource = GlossaryTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')
                ->label('View on site')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (GlossaryTerm $record): string => $record->url())
                ->openUrlInNewTab()
                ->visible(fn (GlossaryTerm $record): bool => (bool) $record->is_published),
            DeleteAction::make(),
        ];
    }
}
