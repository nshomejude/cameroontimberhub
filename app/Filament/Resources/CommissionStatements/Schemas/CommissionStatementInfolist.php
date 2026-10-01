<?php

namespace App\Filament\Resources\CommissionStatements\Schemas;

use App\Enums\CommissionDepositMethod;
use App\Enums\CommissionDepositStatus;
use App\Enums\CommissionStatementStatus;
use App\Models\CommissionPaymentSetting;
use App\Models\CommissionStatement;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Commission statement detail, shared by /admin (finance) and the exporter
 * panel (supplier). The supplier view leads with WHERE to pay (the platform
 * payment instructions, CommissionPaymentSetting); the admin view adds the
 * company and the integrity-chain check.
 */
class CommissionStatementInfolist
{
    public static function configure(Schema $schema, bool $admin = false): Schema
    {
        $money = fn (string $column) => fn ($state, CommissionStatement $record) => $record->money($state);

        $summary = Section::make('Statement')
            ->columns(3)
            ->schema(array_values(array_filter([
                TextEntry::make('statement_number')->label('Number'),
                $admin ? TextEntry::make('company.name')->label('Company') : null,
                TextEntry::make('period')->label('Period')->state(fn (CommissionStatement $record) => $record->periodLabel()),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (CommissionStatementStatus $state) => $state->label())
                    ->color(fn (CommissionStatementStatus $state) => $state->color()),
                TextEntry::make('issued_at')->label('Issued')->dateTime('d M Y'),
                TextEntry::make('due_date')->label('Due by')->date('d M Y'),
                TextEntry::make('charges_amount')->label('Commission charged')->formatStateUsing($money('charges_amount')),
                TextEntry::make('adjustments_amount')->label('Credits / adjustments')->formatStateUsing($money('adjustments_amount')),
                TextEntry::make('total_amount')->label('Total')->formatStateUsing($money('total_amount'))->weight('bold'),
                TextEntry::make('amount_paid')->label('Paid (confirmed)')->formatStateUsing($money('amount_paid')),
                TextEntry::make('amount_due')->label('Amount due')
                    ->state(fn (CommissionStatement $record) => $record->money($record->outstanding()))
                    ->weight('bold')
                    ->color(fn (CommissionStatement $record) => $record->isPastDue() ? 'danger' : null),
                TextEntry::make('void_reason')->label('Void reason')->visible(fn (CommissionStatement $record) => $record->isVoid()),
                $admin ? TextEntry::make('integrity')->label('Integrity chain')->badge()
                    ->state(fn (CommissionStatement $record): string => $record->verifiesIntegrity() ? 'OK' : 'TAMPERED')
                    ->color(fn (string $state): string => $state === 'OK' ? 'success' : 'danger') : null,
            ])));

        $instructions = Section::make('How to pay')
            ->description('Pay by Mobile Money or bank deposit, quoting the statement number as the payment reason, then use "Report a deposit" with the transaction reference.')
            ->schema([
                TextEntry::make('payment_instructions')
                    ->hiddenLabel()
                    ->state(fn () => self::instructionsHtml())
                    ->html(),
            ])
            ->visible(fn (CommissionStatement $record) => $admin === false && $record->isOpen());

        $lines = Section::make('Orders')
            ->schema([
                RepeatableEntry::make('lines')->hiddenLabel()
                    ->schema([
                        TextEntry::make('order_reference')->label('Order'),
                        TextEntry::make('kind')->badge()->formatStateUsing(fn (string $state) => $state === 'adjustment' ? 'Credit adjustment' : 'Commission'),
                        TextEntry::make('charged_at')->label('Charged')->date('d M Y')->placeholder('—'),
                        TextEntry::make('order_subtotal')->label('Order value')
                            ->formatStateUsing(fn ($state, $record) => CommissionStatement::format($state, $record->statement->currency->value)),
                        TextEntry::make('commission_rate')->label('Rate')
                            ->formatStateUsing(fn ($state) => $state === null ? '—' : rtrim(rtrim(number_format((float) $state * 100, 2), '0'), '.').'%'),
                        TextEntry::make('amount')->label('Billed')
                            ->formatStateUsing(fn ($state, $record) => CommissionStatement::format($state, $record->statement->currency->value)),
                    ])
                    ->columns(6),
            ]);

        $deposits = Section::make('Deposits')
            ->schema([
                RepeatableEntry::make('deposits')->hiddenLabel()
                    ->schema([
                        TextEntry::make('method')->formatStateUsing(fn (CommissionDepositMethod $state) => $state->label()),
                        TextEntry::make('transaction_reference')->label('Reference'),
                        TextEntry::make('amount')->formatStateUsing(fn ($state, $record) => $record->money($state)),
                        TextEntry::make('paid_on')->label('Paid on')->date('d M Y'),
                        TextEntry::make('status')->badge()
                            ->formatStateUsing(fn (CommissionDepositStatus $state) => $state->label())
                            ->color(fn (CommissionDepositStatus $state) => $state->color()),
                        TextEntry::make('rejection_reason')->label('Rejection reason')->placeholder('—'),
                    ])
                    ->columns(6),
            ])
            ->collapsed(fn (CommissionStatement $record) => $record->deposits->isEmpty());

        return $schema->components([$summary, $instructions, $lines, $deposits]);
    }

    /** The platform payment channels as escaped HTML (or a "not configured yet" note). */
    public static function instructionsHtml(): HtmlString
    {
        $settings = CommissionPaymentSetting::current();
        $channels = $settings->instructions();

        if ($channels === []) {
            return new HtmlString('<p>'.e('Payment details are being set up — please contact support before paying.').'</p>');
        }

        $labels = [
            'number' => 'Number', 'account_name' => 'Account name', 'bank_name' => 'Bank',
            'account_number' => 'Account number', 'iban' => 'IBAN', 'swift' => 'SWIFT / BIC', 'branch' => 'Branch',
        ];

        $html = '';

        foreach ($channels as $channel) {
            $html .= '<p><strong>'.e($channel['label']).'</strong><br>';
            foreach ($channel['details'] as $key => $value) {
                $html .= e($labels[$key] ?? $key).': '.e($value).'<br>';
            }
            $html .= '</p>';
        }

        if (filled($settings->extra_instructions)) {
            $html .= '<p>'.nl2br(e((string) $settings->extra_instructions)).'</p>';
        }

        return new HtmlString($html);
    }
}
