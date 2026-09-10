<?php

namespace App\Filament\Resources\PaymentSettings\Tables;

use App\Models\PaymentSetting;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->badge(),
                TextColumn::make('environment')->badge(),
                IconColumn::make('is_live')->label('Live')->boolean(),
                TextColumn::make('credentials_status')
                    ->label('Credentials')
                    ->badge()
                    ->state(fn (PaymentSetting $record): string => $record->isConfigured()
                        ? 'Configured'
                        : (filled($record->credentials) ? 'Partial' : 'Not set'))
                    ->color(fn (string $state): string => match ($state) {
                        'Configured' => 'success',
                        'Partial' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('updatedBy.name')->label('Last changed by')->placeholder('—'),
                TextColumn::make('updated_at')->label('Last changed')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('provider')
            ->paginated(false);
    }
}
