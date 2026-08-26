<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(60)->wrap(),
                TextColumn::make('slug')->searchable()->color('gray')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (ArticleCategory $state): string => $state->shortLabel())
                    ->color(fn (ArticleCategory $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ArticleStatus $state): string => $state->label())
                    ->color(fn (ArticleStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('published_at')->label('Published')->dateTime('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('reading_minutes')
                    ->label('Read')
                    ->state(fn (Article $record): string => $record->readingTime().' min')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('faqs')
                    ->label('FAQs')
                    ->boolean()
                    ->state(fn (Article $record): bool => $record->faqPairs() !== []),
                IconColumn::make('sources')
                    ->label('Cited')
                    ->boolean()
                    ->state(fn (Article $record): bool => $record->sourceList() !== []),
                TextColumn::make('author_name')->label('Author')->placeholder('Editorial')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ArticleStatus::options()),
                SelectFilter::make('category')->options(ArticleCategory::options()),
                Filter::make('scheduled')
                    ->label('Scheduled (future-dated)')
                    ->query(fn ($query) => $query
                        ->where('status', ArticleStatus::Published->value)
                        ->where('published_at', '>', now())),
            ])
            ->defaultSort('published_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
