<?php

namespace App\Filament\Exporter\Resources\Products;

use App\Filament\Exporter\Resources\Products\Pages\CreateProduct;
use App\Filament\Exporter\Resources\Products\Pages\EditProduct;
use App\Filament\Exporter\Resources\Products\Pages\ListProducts;
use App\Filament\Exporter\Resources\Products\Schemas\ProductForm;
use App\Filament\Exporter\Resources\Products\Tables\ProductsTable;
use App\Domain\Catalog\Queries\ListSupplierProductsQuery;
use App\Models\Product;
use App\Support\Bus\QueryBus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
