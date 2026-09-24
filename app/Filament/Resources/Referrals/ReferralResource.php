<?php

namespace App\Filament\Resources\Referrals;

use App\Filament\Resources\Referrals\Pages\ListReferrals;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * /admin → Referrals: every user who signed up with a referral code, and
 * who referred them. Read-only (`billing.view`).
 */
class ReferralResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'referrals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Referrals';

    protected static ?string $modelLabel = 'referral';

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('referred_by_user_id');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('billing.view');
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
                TextColumn::make('name')->label('Referred user')->searchable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('companies.legal_name')->label('Company')->placeholder('—'),
                TextColumn::make('referrer_name')->label('Referred by')
                    ->state(fn (User $record) => User::find($record->referred_by_user_id)?->name ?? '—'),
                TextColumn::make('referred_at')->label('Joined')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('referred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferrals::route('/'),
        ];
    }
}
