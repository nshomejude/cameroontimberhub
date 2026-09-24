<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Resources\Announcements\Tables\AnnouncementsTable;
use App\Models\Announcement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * /admin → Announcements (mobile app home-screen feed).
 *
 * Gated on `pages.manage` — the existing lightweight-CMS permission
 * (already granted to super_admin/admin/content_manager) rather than a new
 * one, since publishing an announcement is the same kind of authority as
 * publishing a Page.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.announcements');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.content');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.announcement_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.announcement_many');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('pages.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
