<?php

namespace App\Filament\Resources\CommissionStatements\Support;

use App\Enums\CommissionDepositMethod;
use App\Models\CommissionDeposit;
use App\Models\CommissionStatement;
use App\Services\Commission\CommissionCollectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The finance-side commission-collection actions shared by /admin →
 * Commission statements and → Commission deposits. Every action is visible
 * only with `payments.manage` and goes through
 * App\Services\Commission\CommissionCollectionService, which re-checks the
 * permission and writes the activity log.
 */
class CommissionCollectionActions
{
    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can('payments.manage');
    }

    private static function run(callable $fn, string $success): void
    {
        try {
            $fn();
            Notification::make()->title($success)->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('Not saved')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
        } catch (AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function confirmDeposit(): Action
    {
        return Action::make('confirmDeposit')
            ->label('Confirm')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Confirm commission deposit')
            ->modalDescription(fn (CommissionDeposit $record) => 'Only confirm once you have seen '.$record->money()
                .' (ref. '.$record->transaction_reference.') arrive on the platform\'s '.$record->method->label().' account.')
            ->schema([
                TextInput::make('amount_received')
                    ->label('Amount actually received')
                    ->numeric()->minValue(0.01)->required()
                    ->default(fn (CommissionDeposit $record) => (string) $record->amount),
                Textarea::make('note')->label('Internal note')->maxLength(500),
            ])
            ->visible(fn (CommissionDeposit $record) => self::canManage() && $record->isPending())
            ->action(fn (CommissionDeposit $record, array $data) => self::run(
                fn () => app(CommissionCollectionService::class)->confirm($record, auth()->user(), (string) $data['amount_received'], $data['note'] ?? null),
                'Deposit confirmed',
            ));
    }

    public static function rejectDeposit(): Action
    {
        return Action::make('rejectDeposit')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Reject commission deposit')
            ->schema([
                Textarea::make('reason')->label('Reason (sent to the supplier)')->required()->minLength(3)->maxLength(1000),
            ])
            ->visible(fn (CommissionDeposit $record) => self::canManage() && $record->isPending())
            ->action(fn (CommissionDeposit $record, array $data) => self::run(
                fn () => app(CommissionCollectionService::class)->reject($record, auth()->user(), (string) $data['reason']),
                'Deposit rejected',
            ));
    }

    /** Streams the private proof file; any staff member who can see the deposit (`billing.view`). */
    public static function downloadProof(): Action
    {
        return Action::make('downloadProof')
            ->label('Proof')
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->visible(fn (CommissionDeposit $record) => $record->hasProof() && (bool) auth()->user()?->can('billing.view'))
            ->action(function (CommissionDeposit $record): ?StreamedResponse {
                abort_unless((bool) auth()->user()?->can('billing.view'), 403);

                $disk = Storage::disk($record->proof_disk);

                if (! $disk->exists($record->proof_path)) {
                    Notification::make()->title('The proof file is missing from storage.')->danger()->send();

                    return null;
                }

                return $disk->download($record->proof_path, $record->proof_original_name ?: basename($record->proof_path));
            });
    }

    public static function recordDeposit(): Action
    {
        return Action::make('recordDeposit')
            ->label('Record deposit')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->modalHeading('Record a deposit received without a supplier report')
            ->modalDescription(fn (CommissionStatement $record) => 'Outstanding on '.$record->statement_number.': '.$record->money($record->outstanding())
                .'. The deposit is recorded as confirmed and the supplier is notified.')
            ->schema([
                Select::make('method')->options(CommissionDepositMethod::options())->required(),
                TextInput::make('amount')->label('Amount received')->numeric()->minValue(0.01)->required()
                    ->default(fn (CommissionStatement $record) => $record->outstanding()),
                TextInput::make('transaction_reference')->label('Transaction reference')->required()->minLength(3)->maxLength(100),
                DatePicker::make('paid_on')->label('Date received')->required()->maxDate(now())->default(now()),
                Textarea::make('notes')->maxLength(1000),
            ])
            ->visible(fn (CommissionStatement $record) => self::canManage() && $record->isOpen())
            ->action(fn (CommissionStatement $record, array $data) => self::run(
                fn () => app(CommissionCollectionService::class)->recordDeposit($record, auth()->user(), $data),
                'Deposit recorded',
            ));
    }

    public static function voidStatement(): Action
    {
        return Action::make('voidStatement')
            ->label('Void')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Withdraws the statement (issued in error). Its orders are billed again on the next statement run; '
                .'a statement with confirmed payments cannot be voided.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->minLength(3)->maxLength(1000),
            ])
            ->visible(fn (CommissionStatement $record) => self::canManage() && ! $record->isVoid()
                && bccomp((string) $record->amount_paid, '0', 2) === 0)
            ->action(fn (CommissionStatement $record, array $data) => self::run(
                fn () => app(CommissionCollectionService::class)->void($record, auth()->user(), (string) $data['reason']),
                'Statement voided',
            ));
    }
}
