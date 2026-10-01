<?php

namespace App\Filament\Exporter\Resources\TransformationRequests\Schemas;

use App\Enums\TransformationRequestStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransformationRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')
                ->columns(2)
                ->schema([
                    TextEntry::make('reference_code')->label('Reference'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (TransformationRequestStatus $state) => $state->label())
                        ->color(fn (TransformationRequestStatus $state) => $state->color()),
                    TextEntry::make('requesterCompany.legal_name')->label('Requester'),
                    TextEntry::make('providerCompany.legal_name')->label('Provider'),
                    TextEntry::make('service')->badge(),
                    TextEntry::make('species.common_name')->label('Species')->placeholder('—'),
                    TextEntry::make('volume_m3')->label('Volume (m³)')->numeric(3),
                    TextEntry::make('deadline')->date()->placeholder('—'),
                    TextEntry::make('input_description')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('target_spec')->label('Target spec')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Quote & outcome')
                ->columns(2)
                ->schema([
                    TextEntry::make('quote_amount')->label('Quote amount')->numeric(2)->placeholder('—'),
                    TextEntry::make('quote_currency')->label('Currency')->placeholder('—'),
                    TextEntry::make('quote_lead_time_days')->label('Lead time (days)')->placeholder('—'),
                    TextEntry::make('quote_notes')->label('Quote notes')->placeholder('—'),
                    TextEntry::make('decline_reason')->placeholder('—'),
                    TextEntry::make('lot_transformation_id')->label('Ledger entry')->placeholder('—'),
                    TextEntry::make('accepted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('started_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                ]),
        ]);
    }
}
