<?php

namespace App\Filament\Resources\SupportTickets;

use App\Filament\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTickets\Tables\SupportTicketsTable;
use App\Models\SupportTicket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Staff inbox for support tickets opened from the mobile app. Tickets are
 * authored by users, so there is no create/edit form — only list/filter,
 * view thread, reply and status change, gated by `support.manage`.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return 'Support tickets';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.trust_safety');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = SupportTicket::query()->where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function canViewAny(): bool
    {
        return SupportTicket::isStaff(auth()->user());
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
        return SupportTicketsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
        ];
    }
}
