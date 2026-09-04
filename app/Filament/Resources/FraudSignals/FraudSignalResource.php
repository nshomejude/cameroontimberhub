<?php

namespace App\Filament\Resources\FraudSignals;

use App\Filament\Resources\FraudSignals\Pages\ListFraudSignals;
use App\Filament\Resources\FraudSignals\Tables\FraudSignalsTable;
use App\Models\FraudSignal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Blueprint §25 anti-fraud detection: list/view-only admin surface for
 * FraudSignal rows raised by FraudDetectionService. Detection/alerting
 * only — there is no create form (signals are raised by the system, never
 * authored by staff) and reviewing one only ever updates its own status,
 * never touches the flagged subject.
 */
class FraudSignalResource extends Resource
{
    protected static ?string $model = FraudSignal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $recordTitleAttribute = 'id';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('fraud.review');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('fraud.review');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return FraudSignalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFraudSignals::route('/'),
        ];
    }
}
