<?php

namespace App\Filament\Resources\Inquiries;

use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Inquiries\Tables\InquiriesTable;
use App\Models\CompanyInquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InquiryResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.inquiries');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.leads');
    }
    protected static ?string $model = CompanyInquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;



    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('inquiries.review');
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
        return InquiriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
        ];
    }
}
