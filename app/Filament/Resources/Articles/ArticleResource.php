<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Resources\Articles\Tables\ArticlesTable;
use App\Models\Article;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ArticleResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.insights_articles');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.content');
    }
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;


    protected static ?string $recordTitleAttribute = 'title';


    protected static ?int $navigationSort = 1;

    /**
     * Editorial content is governed by the same permission as the CMS pages —
     * `pages.manage`, held by super_admin, admin and content_manager. Staff
     * without it never see the resource, and a non-staff account never reaches
     * the /admin panel at all (User::canAccessPanel).
     */
    protected static function hasPermission(): bool
    {
        return auth()->user()?->can('pages.manage') ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::hasPermission();
    }

    public static function canCreate(): bool
    {
        return static::hasPermission();
    }

    public static function canEdit($record): bool
    {
        return static::hasPermission();
    }

    public static function canDelete($record): bool
    {
        return static::hasPermission();
    }

    public static function canDeleteAny(): bool
    {
        return static::hasPermission();
    }

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
