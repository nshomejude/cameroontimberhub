<?php

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Models\CreditNote;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Credit note')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('credit_note_number')->label('Number'),
                        TextEntry::make('invoice.invoice_number')->label('Against invoice'),
                        TextEntry::make('company.name')->label('Company'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state->label())
                            ->color(fn ($state) => $state->color()),
                        TextEntry::make('issuedBy.name')->label('Issued by')->placeholder('—'),
                        TextEntry::make('issued_at')->dateTime('d M Y H:i'),
                        TextEntry::make('integrity')
                            ->label('Integrity chain')
                            ->badge()
                            ->state(fn (CreditNote $record): string => $record->verifiesIntegrity() ? 'OK' : 'TAMPERED')
                            ->color(fn (string $state): string => $state === 'OK' ? 'success' : 'danger'),
                        TextEntry::make('reason')->columnSpanFull(),
                    ]),
                Section::make('Line items')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->schema([
                                TextEntry::make('description'),
                                TextEntry::make('quantity'),
                                TextEntry::make('unit_amount'),
                                TextEntry::make('line_total'),
                            ])
                            ->columns(4),
                    ]),
                Section::make('Amounts')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal_amount'),
                        TextEntry::make('tax_amount'),
                        TextEntry::make('total_amount'),
                    ]),
            ]);
    }
}
