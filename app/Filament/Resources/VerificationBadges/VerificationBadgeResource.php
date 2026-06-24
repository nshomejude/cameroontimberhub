<?php

namespace App\Filament\Resources\VerificationBadges;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Filament\Resources\VerificationBadges\Pages\ListVerificationBadges;
use App\Models\VerificationBadge;
use App\Services\BadgeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VerificationBadgeResource extends Resource
{
    protected static ?string $model = VerificationBadge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Verification badges';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('verification.review');
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
                TextColumn::make('company.legal_name')->label('Company')->searchable()->sortable(),
                TextColumn::make('badge_type')
                    ->label('Badge')
                    ->badge()
                    ->formatStateUsing(fn (BadgeType $state): string => $state->label())
                    ->color('info'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BadgeStatus $state): string => $state->label())
                    ->color(fn (BadgeStatus $state): string => $state->color()),
                TextColumn::make('issued_at')->date('d M Y')->sortable()->label('Issued'),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('No expiry')->sortable()->label('Valid until')
                    ->color(fn (VerificationBadge $record): string => $record->valid_until?->isPast() ? 'danger' : 'success'),
                TextColumn::make('verifiedBy.name')->label('Issued by')->placeholder('—')->toggleable(),
                IconColumn::make('is_public')->boolean()->label('Public')->toggleable(),
                TextColumn::make('revoked_reason')->label('Revoke reason')->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(BadgeStatus::cases())->mapWithKeys(fn (BadgeStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('badge_type')
                    ->label('Badge type')
                    ->multiple()
                    ->options(BadgeType::options()),
            ])
            ->defaultSort('issued_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (VerificationBadge $record): bool => $record->status === BadgeStatus::Active)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Revocation reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (VerificationBadge $record, array $data): void {
                        app(BadgeService::class)->revoke($record, $data['reason'], auth()->user());
                        Notification::make()->title('Badge revoked')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerificationBadges::route('/'),
        ];
    }
}
