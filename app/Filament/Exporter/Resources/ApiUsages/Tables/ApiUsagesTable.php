<?php

namespace App\Filament\Exporter\Resources\ApiUsages\Tables;

use App\Models\ApiKeyMeta;
use App\Models\ApiKeyUsageDaily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApiUsagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('personalAccessToken.name')->label('Key name'),
                TextColumn::make('rate_limit_tier')->label('Tier')->badge(),
                TextColumn::make('requests_today')
                    ->label('Requests today')
                    ->state(fn (ApiKeyMeta $record): int => (int) ApiKeyUsageDaily::query()
                        ->where('personal_access_token_id', $record->personal_access_token_id)
                        ->where('date', now()->toDateString())
                        ->sum('request_count')),
                TextColumn::make('requests_this_month')
                    ->label('Requests this month')
                    ->state(fn (ApiKeyMeta $record): int => (int) ApiKeyUsageDaily::query()
                        ->where('personal_access_token_id', $record->personal_access_token_id)
                        ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                        ->sum('request_count')),
                TextColumn::make('personalAccessToken.last_used_at')
                    ->label('Last used')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Never'),
            ])
            ->defaultSort('id', 'desc');
    }
}
