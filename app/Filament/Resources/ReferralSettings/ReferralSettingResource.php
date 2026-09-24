<?php

namespace App\Filament\Resources\ReferralSettings;

use App\Filament\Resources\ReferralSettings\Pages\ListReferralSettings;
use App\Models\ReferralSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * /admin → Referral settings: the single ReferralSetting row (enabled,
 * rate %, basis, one-time). Same single-row-table pattern as
 * PaymentSettingResource. Gated by `pricing.manage`.
 */
class ReferralSettingResource extends Resource
{
    protected static ?string $model = ReferralSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Referral settings';

    protected static ?string $modelLabel = 'referral settings';

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('enabled')->label('Programme enabled'),
            TextInput::make('rate_percent')->label('Commission rate (%)')
                ->numeric()->minValue(0)->maxValue(100)->step(0.01)->required(),
            Select::make('basis')->options(['subscription' => 'Subscription payment'])->required(),
            Toggle::make('one_time')->label('One-time (first subscription payment only)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('rate_percent')->label('Rate')->suffix('%'),
                TextColumn::make('basis')->badge(),
                IconColumn::make('one_time')->label('One-time')->boolean(),
                TextColumn::make('updated_at')->label('Last changed')->dateTime('d M Y H:i'),
            ])
            ->paginated(false)
            ->recordActions([
                EditAction::make()->mutateDataUsing(function (array $data): array {
                    $data['updated_by'] = auth()->id();

                    return $data;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralSettings::route('/'),
        ];
    }
}
