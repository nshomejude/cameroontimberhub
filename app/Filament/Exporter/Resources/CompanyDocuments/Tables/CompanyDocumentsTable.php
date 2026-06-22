<?php

namespace App\Filament\Exporter\Resources\CompanyDocuments\Tables;

use App\Enums\DocumentStatus;
use App\Models\CompanyDocument;
use App\Services\DocumentService;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('documentType.name')->label('Type')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state): string => $state->label())
                    ->color(fn (DocumentStatus $state): string => $state->color()),
                TextColumn::make('expiry_date')->date('d M Y')->placeholder('—'),
                TextColumn::make('created_at')->label('Uploaded')->date('d M Y')->sortable(),
                TextColumn::make('rejection_reason')->label('Reviewer feedback')->placeholder('—')->wrap()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (CompanyDocument $record): string => app(DocumentService::class)->signedDownloadUrl($record, auth()->user()))
                    ->openUrlInNewTab(),
            ]);
    }
}
