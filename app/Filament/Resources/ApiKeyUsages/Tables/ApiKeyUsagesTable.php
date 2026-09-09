<?php

namespace App\Filament\Resources\ApiKeyUsages\Tables;

use App\Models\ApiKeyMeta;
use App\Models\ApiKeyUsageDaily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApiKeyUsagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => ApiKeyMeta::query()->with(['company', 'personalAccessToken']))
            ->columns([
                TextColumn::make('company.name')->label('Company'),
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
                TextColumn::make('revoked_at')->label('Revoked')->dateTime('d M Y H:i')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc');
    }
}
