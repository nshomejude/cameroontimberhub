<?php

namespace App\Filament\Exporter\Resources\Products;

use App\Domain\Catalog\ProductPublishingRules;
use App\Domain\Catalog\Queries\ListSupplierProductsQuery;
use App\Filament\Exporter\Resources\Products\Pages\CreateProduct;
use App\Filament\Exporter\Resources\Products\Pages\EditProduct;
use App\Filament\Exporter\Resources\Products\Pages\ListProducts;
use App\Filament\Exporter\Resources\Products\Schemas\ProductForm;
use App\Filament\Exporter\Resources\Products\Tables\ProductsTable;
use App\Models\Company;
use App\Models\Product;
use App\Support\Bus\QueryBus;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ProductResource extends Resource
{
    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.products');
    }

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    /** Hidden for logistics / carbon-developer companies (OrganisationType::hasTimberCatalogue()). */
    public static function canViewAny(): bool
    {
        $company = auth()->user()?->companies()->first();

        return $company !== null && ($company->type?->hasTimberCatalogue() ?? true);
    }

    /**
     * Writes are limited to owner/manager members of the company (see
     * ProductPublishingRules::canManageProducts()); `member` is read-only.
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return ProductPublishingRules::canManageProducts($user, $user?->companies()->first());
    }

    public static function canEdit($record): bool
    {
        return ProductPublishingRules::canManageProducts(auth()->user(), $record->company_id);
    }

    public static function canDelete($record): bool
    {
        return ProductPublishingRules::canManageProducts(auth()->user(), $record->company_id);
    }

    public static function canDeleteAny(): bool
    {
        return static::canCreate();
    }

    /**
     * Publish gate shared by the Create/Edit pages and the table toggle.
     * Returns a human-readable reason when publishing must be refused at the
     * company level (suspended/rejected/archived), and throws a
     * ValidationException (form-field keyed) when the listing misses the
     * quality minimum.
     *
     * @param  array<string, mixed>  $attributes  effective listing values
     */
    public static function publishBlockReason(Company $company, array $attributes, string $errorPrefix = 'data.'): ?string
    {
        if (($reason = ProductPublishingRules::companyPublishBlock($company)) !== null) {
            return $reason;
        }

        $gaps = ProductPublishingRules::qualityGaps($attributes);
        if ($gaps !== []) {
            throw ValidationException::withMessages(
                collect($gaps)->mapWithKeys(fn (string $m, string $k): array => [$errorPrefix.$k => $m])->all()
            );
        }

        return null;
    }

    /** Notification shown after a successful publish — a warning when buyers still cannot see it. */
    public static function publishedNotification(Company $company): Notification
    {
        $gaps = $company->publicVisibilityGaps();

        if ($gaps === []) {
            return Notification::make()->success()->title(__('messages.filament.product.notify_published'));
        }

        return Notification::make()
            ->warning()
            ->persistent()
            ->title(__('Published, but not yet visible to buyers'))
            ->body(__('Published, but not yet visible to buyers because your company profile still needs: :items', [
                'items' => implode('; ', $gaps),
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        // Company-scoping moved behind a named Query (architecture plan,
        // gap 3 — Catalog read coverage). ListSupplierProductsHandler
        // reproduces the former inline scope exactly.
        return app(QueryBus::class)->dispatch(new ListSupplierProductsQuery(auth()->id()));
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
