<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\Subscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * /admin → Subscriptions (billing engine M6).
 *
 * Read-only operational visibility into the renewal state machine: which
 * companies are Active / Trialing / PastDue / Expired, when each term renews,
 * how long grace runs, and the snapshotted term price. No create/edit/delete —
 * subscriptions are driven by payments and the `subscriptions:process-renewals`
 * job. Gated by `billing.view` (super_admin + admin + finance_officer).
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationLabel(): string
    {
        return 'Subscriptions';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
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
                TextColumn::make('company.name')->label('Company')->searchable()->sortable(),
                TextColumn::make('plan.name')->label('Plan')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state) => $state->label())
                    ->color(fn (SubscriptionStatus $state) => $state->color())
                    ->sortable(),
                TextColumn::make('price_amount')
                    ->label('Term price')
                    ->formatStateUsing(fn ($state, Subscription $record) => $state === null
                        ? '—'
                        : number_format((float) $state).' '.($record->price_currency ?? ''))
                    ->sortable(),
                TextColumn::make('trial_ends_at')->label('Trial ends')->dateTime('d M Y')->sortable()->placeholder('—'),
                TextColumn::make('renews_at')->label('Renews')->dateTime('d M Y')->sortable()->placeholder('—'),
                TextColumn::make('grace_until')->label('Grace until')->dateTime('d M Y')->sortable()->placeholder('—'),
                TextColumn::make('renewal_reminded_at')->label('Reminded')->dateTime('d M Y')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(SubscriptionStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()
                ),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
        ];
    }
}
