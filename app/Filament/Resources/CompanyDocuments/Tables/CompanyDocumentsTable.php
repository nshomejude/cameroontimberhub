<?php

namespace App\Filament\Resources\CompanyDocuments\Tables;

use App\Enums\DocumentStatus;
use App\Models\CompanyDocument;
use App\Services\DocumentService;
use App\Services\VerificationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompanyDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.legal_name')->label('Company')->searchable()->sortable(),
                TextColumn::make('documentType.name')->label('Type')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DocumentStatus $state): string => $state->label())
                    ->color(fn (DocumentStatus $state): string => $state->color()),
                TextColumn::make('expiry_date')->date('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Uploaded')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(DocumentStatus::cases())->mapWithKeys(fn (DocumentStatus $s) => [$s->value => $s->label()])->all())
                    ->default(DocumentStatus::Pending->value),
            ])
            ->defaultSort('created_at', 'asc')
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (CompanyDocument $record): string => app(DocumentService::class)->signedDownloadUrl($record, auth()->user()))
                    ->openUrlInNewTab(),

                ActionGroup::make([
                    Action::make('approveDocument')->label('Approve')
                        ->icon('heroicon-o-check-circle')->color('success')
                        ->visible(fn (CompanyDocument $record): bool => $record->status === DocumentStatus::Pending && static::canReview())
                        ->schema([
                            DatePicker::make('expiry_date')
                                ->default(fn (CompanyDocument $record) => $record->expiry_date)
                                ->required(fn (CompanyDocument $record): bool => (bool) $record->documentType?->requires_expiry)
                                ->helperText('Required for permits and certificates.'),
                        ])
                        ->action(function (CompanyDocument $record, array $data): void {
                            app(VerificationService::class)->approveDocument($record, auth()->user(), $data['expiry_date'] ?? null);
                            Notification::make()->title('Document approved')->success()->send();
                        }),

                    Action::make('rejectDocument')->label('Reject')
                        ->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (CompanyDocument $record): bool => $record->status === DocumentStatus::Pending && static::canReview())
                        ->schema([Textarea::make('reason')->required()->maxLength(500)])
                        ->action(function (CompanyDocument $record, array $data): void {
                            app(VerificationService::class)->rejectDocument($record, $data['reason'], auth()->user());
                            Notification::make()->title('Document rejected')->success()->send();
                        }),

                    Action::make('needsCorrection')->label('Request correction')
                        ->icon('heroicon-o-arrow-uturn-left')->color('warning')
                        ->visible(fn (CompanyDocument $record): bool => $record->status === DocumentStatus::Pending && static::canReview())
                        ->schema([Textarea::make('reason')->label('Instructions')->required()->maxLength(500)])
                        ->action(function (CompanyDocument $record, array $data): void {
                            app(VerificationService::class)->requestCorrection($record, $data['reason'], auth()->user());
                            Notification::make()->title('Correction requested')->success()->send();
                        }),
                ])->label('Review')->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    protected static function canReview(): bool
    {
        return (bool) auth()->user()?->can('documents.review');
    }
}
