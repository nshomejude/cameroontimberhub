<?php

namespace App\Filament\Exporter\Resources\LotTransformations;

use App\Filament\Exporter\Resources\LotTransformations\Pages\ListLotTransformations;
use App\Filament\Exporter\Resources\LotTransformations\Pages\ViewLotTransformation;
use App\Models\LotTransformation;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only mass-balance ledger for the caller's company as PROCESSOR
 * (LotTransformation.processor_company_id). Rows are written only by
 * LotTransformation::recordFor() (e.g. TransformationRequestService::completeJob()).
 */
class LotTransformationResource extends Resource
{
    protected static ?string $model = LotTransformation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Lot transformations';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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

    public static function getEloquentQuery(): Builder
    {
        $companyId = auth()->user()?->companies()->first()?->getKey();

        return parent::getEloquentQuery()->when(
            $companyId !== null,
            fn (Builder $q) => $q->where('processor_company_id', $companyId),
            fn (Builder $q) => $q->whereRaw('1 = 0'),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('transformation_type')->badge(),
            TextEntry::make('processed_at')->dateTime()->placeholder('—'),
            TextEntry::make('input_volume_m3')->label('Input (m³)')->numeric(3),
            TextEntry::make('output_volume_m3')->label('Output (m³)')->numeric(3),
            TextEntry::make('loss_volume_m3')->label('Loss (m³)')->numeric(3),
            TextEntry::make('transformation_ratio')->label('Ratio')->numeric(4),
            TextEntry::make('inputLots.lot_number')->label('Input lots')->badge()->placeholder('—'),
            TextEntry::make('outputLots.lot_number')->label('Output lots')->badge()->placeholder('—'),
            TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transformation_type')->label('Type')->badge(),
                TextColumn::make('input_volume_m3')->label('Input (m³)')->numeric(3)->sortable(),
                TextColumn::make('output_volume_m3')->label('Output (m³)')->numeric(3)->sortable(),
                TextColumn::make('loss_volume_m3')->label('Loss (m³)')->numeric(3),
                TextColumn::make('transformation_ratio')->label('Ratio')->numeric(4),
                TextColumn::make('processed_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('processed_at', 'desc')
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No lot transformations recorded yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLotTransformations::route('/'),
            'view' => ViewLotTransformation::route('/{record}'),
        ];
    }
}
