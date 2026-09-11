<?php

namespace App\Filament\Resources\Invoices\Support;

use App\Models\Invoice;
use App\Services\Billing\InvoiceIssuer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

/**
 * The two mutations allowed on an issued Invoice from /admin (billing engine
 * M4). Both go through App\Services\Billing\InvoiceIssuer / Invoice::void()
 * and never touch a hash-payload column, so `invoices:verify-chain` stays
 * green afterwards.
 *
 * TODO M-Phase3 §7.6: refunds/credits over 100,000 XAF / $200 need a second
 * approver + fresh 2FA. That gate is NOT built here — this is the plain
 * admin-issue flow only.
 */
class InvoiceRecordActions
{
    public static function void(): Action
    {
        return Action::make('void')
            ->label(__('messages.billing.invoice_void_action'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Invoice $record): bool => ! $record->isVoid())
            ->schema([
                Textarea::make('reason')->label(__('messages.billing.invoice_void_reason'))->required()->rows(3),
            ])
            ->action(function (Invoice $record, array $data): void {
                $record->void(auth()->user(), $data['reason']);

                Notification::make()->title(__('messages.billing.invoice_voided_notice'))->success()->send();
            });
    }

    public static function issueCreditNote(): Action
    {
        return Action::make('issueCreditNote')
            ->label(__('messages.billing.credit_note_action'))
            ->icon('heroicon-o-receipt-refund')
            ->color('warning')
            ->visible(fn (Invoice $record): bool => ! $record->isVoid())
            ->schema([
                Textarea::make('reason')->label(__('messages.billing.credit_note_reason'))->required()->rows(3),
                TextInput::make('amount')
                    ->label(__('messages.billing.credit_note_amount'))
                    ->numeric()
                    ->minValue(0.01)
                    ->helperText(__('messages.billing.credit_note_amount_hint')),
            ])
            ->action(function (Invoice $record, array $data): void {
                try {
                    $lines = null;
                    if (! empty($data['amount'])) {
                        $lines = [[
                            'description' => __('messages.billing.credit_note_partial_line', ['number' => $record->invoice_number]),
                            'quantity' => 1,
                            'unit_amount' => (string) $data['amount'],
                        ]];
                    }

                    app(InvoiceIssuer::class)->issueCreditNote($record, $data['reason'], auth()->user(), $lines);

                    Notification::make()->title(__('messages.billing.credit_note_issued_notice'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
