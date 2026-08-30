<?php

namespace App\Filament\Resources\TimberLots\Schemas;

use App\Enums\TimberLotStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TimberLotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('lot_number'),
            TextEntry::make('company.name')->label('Company'),
            TextEntry::make('species.common_name')->label('Species')->placeholder('—'),
            TextEntry::make('status')->badge()->formatStateUsing(fn (TimberLotStatus $state): string => $state->label()),
            TextEntry::make('quantity')->placeholder('—'),
            TextEntry::make('updated_at')->dateTime(),
        ]);
    }
}
