<?php

namespace App\Filament\Resources\ComplianceCases\Tables;

use App\Enums\ComplianceCaseStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ComplianceCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('owner_type')
                    ->label('Owner type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->searchable(),
                TextColumn::make('owner_id')->label('Owner ID'),
                TextColumn::make('country_code')->badge()->color('gray'),
                TextColumn::make('status')->badge(),
                TextColumn::make('assignedTo.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('opened_at')->dateTime()->sortable(),
                TextColumn::make('closed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(
                    collect(ComplianceCaseStatus::cases())->mapWithKeys(fn (ComplianceCaseStatus $s) => [$s->value => $s->label()])->all()
                ),
            ])
            ->recordActions([
                Action::make('updateStatus')
                    ->label('Update status')
                    ->schema([
                        Select::make('status')
                            ->options(
                                collect(ComplianceCaseStatus::cases())->mapWithKeys(fn (ComplianceCaseStatus $s) => [$s->value => $s->label()])->all()
                            )
                            ->required(),
                    ])
                    ->fillForm(fn ($record): array => ['status' => $record->status?->value])
                    ->action(function ($record, array $data): void {
                        $record->update(['status' => $data['status']]);
                    }),
            ]);
    }
}
