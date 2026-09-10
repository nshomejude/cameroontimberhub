<?php

namespace App\Filament\Exporter\Resources\WebhookSubscriptions;

use App\Filament\Exporter\Resources\WebhookSubscriptions\Pages\CreateWebhookSubscription;
use App\Filament\Exporter\Resources\WebhookSubscriptions\Pages\EditWebhookSubscription;
use App\Filament\Exporter\Resources\WebhookSubscriptions\Pages\ListWebhookSubscriptions;
use App\Filament\Exporter\Resources\WebhookSubscriptions\Schemas\WebhookSubscriptionForm;
use App\Filament\Exporter\Resources\WebhookSubscriptions\Tables\WebhookSubscriptionsTable;
use App\Models\WebhookSubscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company self-service webhook registration (architecture plan, Task 0.5).
 * Mirrors App\Filament\Exporter\Resources\Leads's company-scoping pattern:
 * scoped to the signed-in user's company via Company::scopeDashboardOwned(),
 * so a company can only ever see/manage its own subscriptions and deliveries.
 */
class WebhookSubscriptionResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.webhooks');
    }
    protected static ?string $model = WebhookSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;


    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return WebhookSubscriptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhookSubscriptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookSubscriptions::route('/'),
            'create' => CreateWebhookSubscription::route('/create'),
            'edit' => EditWebhookSubscription::route('/{record}/edit'),
        ];
    }
}
