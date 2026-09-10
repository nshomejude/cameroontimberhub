<?php

namespace App\Filament\Resources\ActivityLog;

use App\Filament\Resources\ActivityLog\Pages\ListActivityLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.audit_log');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.system');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.activity_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.activity_many');
    }
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;





    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y H:i:s')
                    ->sortable()
                    ->timezone(config('app.timezone')),
                TextColumn::make('log_name')
                    ->label('Domain')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->limit(60)
                    ->tooltip(fn (Activity $record): string => $record->description),
                TextColumn::make('causer.name')
                    ->label('Actor')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label('Subject type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->toggleable(),
                TextColumn::make('subject_id')
                    ->label('Subject ID')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('properties')
                    ->label('Properties')
                    ->formatStateUsing(fn ($state): string => $state ? json_encode($state, JSON_PRETTY_PRINT) : '—')
                    ->limit(60)
                    ->tooltip(fn (Activity $record): string => $record->properties ? json_encode($record->properties, JSON_PRETTY_PRINT) : '')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Domain')
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->filter()
                        ->all()),
                SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        'status_changed' => 'Status changed',
                        'plan_assigned' => 'Plan assigned',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLog::route('/'),
        ];
    }
}
