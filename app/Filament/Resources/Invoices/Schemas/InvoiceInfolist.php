<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_number')->label('Number'),
                        TextEntry::make('company.name')->label('Company'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state->label())
                            ->color(fn ($state) => $state->color()),
                        TextEntry::make('issued_at')->dateTime('d M Y H:i'),
                        TextEntry::make('payment_id')->label('Payment ref')->placeholder('—'),
                        TextEntry::make('integrity')
                            ->label('Integrity chain')
                            ->badge()
                            ->state(fn (Invoice $record): string => $record->verifiesIntegrity() ? 'OK' : 'TAMPERED')
                            ->color(fn (string $state): string => $state === 'OK' ? 'success' : 'danger'),
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
                        TextEntry::make('tax_label')->label('Tax')->placeholder('—'),
                        TextEntry::make('tax_amount'),
                        TextEntry::make('total_amount'),
                        TextEntry::make('currency')->formatStateUsing(fn ($state) => $state->value),
                        TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Credit notes')
                    ->schema([
                        RepeatableEntry::make('creditNotes')
                            ->schema([
                                TextEntry::make('credit_note_number'),
                                TextEntry::make('total_amount'),
                                TextEntry::make('reason'),
                                TextEntry::make('issued_at')->dateTime('d M Y'),
                            ])
                            ->columns(4),
                    ])
                    ->collapsed(fn (Invoice $record) => $record->creditNotes->isEmpty()),
            ]);
    }
}
