<?php

namespace App\Filament\Exporter\Resources\CommissionStatements;

use App\Enums\CommissionDepositMethod;
use App\Enums\CommissionStatementStatus;
use App\Filament\Exporter\Resources\CommissionStatements\Pages\ListCommissionStatements;
use App\Filament\Exporter\Resources\CommissionStatements\Pages\ViewCommissionStatement;
use App\Filament\Resources\CommissionStatements\Schemas\CommissionStatementInfolist;
use App\Models\CommissionStatement;
use App\Services\Commission\CommissionCollectionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Supplier side of marketplace-commission collection (owner decision
 * 2026-10-01): the company's monthly commission statements, where to pay
 * (MTN MoMo / Orange Money / bank — the platform payment instructions) and a
 * "Report a deposit" form finance then verifies. Read-only otherwise.
 *
 * Scoped like OrderResource: the user's own companies only; another
 * company's statement 404s. Open to pending-verification companies too.
 */
class CommissionStatementResource extends Resource
{
    protected static ?string $model = CommissionStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Commission';

    protected static ?string $modelLabel = 'commission statement';

    protected static ?string $recordTitleAttribute = 'statement_number';

    protected static ?int $navigationSort = 21;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
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

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function infolist(Schema $schema): Schema
    {
        return CommissionStatementInfolist::configure($schema, admin: false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('statement_number')->label('Statement')->searchable(),
                TextColumn::make('period_start')->label('Period')->date('M Y')->sortable(),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state, CommissionStatement $record) => $record->money($state)),
                TextColumn::make('amount_due')->label('Amount due')
                    ->state(fn (CommissionStatement $record) => $record->money($record->outstanding())),
                TextColumn::make('due_date')->label('Due by')->date('d M Y')->sortable()
                    ->color(fn (CommissionStatement $record) => $record->isPastDue() ? 'danger' : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (CommissionStatementStatus $state) => $state->label())
                    ->color(fn (CommissionStatementStatus $state) => $state->color()),
            ])
            ->defaultSort('period_start', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(CommissionStatementStatus::cases())
                    ->mapWithKeys(fn (CommissionStatementStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::reportDepositAction(),
            ]);
    }

    /** "Report a deposit": goes through CommissionCollectionService::report(), the same path as the API. */
    public static function reportDepositAction(): Action
    {
        return Action::make('reportDeposit')
            ->label('Report a deposit')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->modalHeading(fn (CommissionStatement $record) => 'Report a deposit for '.$record->statement_number)
            ->modalDescription(fn (CommissionStatement $record) => 'Amount due: '.$record->money($record->outstanding())
                .'. Finance will confirm the deposit once it has been received.')
            ->schema(fn (CommissionStatement $record) => [
                Select::make('method')->label('Paid by')->options(CommissionDepositMethod::options())->required(),
                TextInput::make('amount')->numeric()->minValue(0.01)->required()
                    ->default($record->outstanding())
                    ->suffix($record->currency->value),
                TextInput::make('transaction_reference')->label('Transaction reference')
                    ->helperText('The MoMo / Orange Money transaction ID or the bank deposit reference.')
                    ->required()->minLength(3)->maxLength(100),
                DatePicker::make('paid_on')->label('Date paid')->required()->maxDate(now())->default(now()),
                FileUpload::make('proof')->label('Proof (receipt screenshot or PDF, optional)')
                    ->disk(CommissionCollectionService::PROOF_DISK)
                    ->directory('commission-deposits/'.$record->company_id)
                    ->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize(CommissionCollectionService::PROOF_MAX_KB),
                Textarea::make('notes')->maxLength(1000),
            ])
            ->visible(fn (CommissionStatement $record) => $record->isOpen())
            ->action(function (CommissionStatement $record, array $data): void {
                try {
                    app(CommissionCollectionService::class)->report(
                        $record,
                        auth()->user(),
                        [
                            'method' => $data['method'] ?? null,
                            'amount' => $data['amount'] ?? null,
                            'currency' => $record->currency->value,
                            'transaction_reference' => $data['transaction_reference'] ?? null,
                            'paid_on' => $data['paid_on'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ],
                        is_string($data['proof'] ?? null) ? $data['proof'] : null,
                    );

                    Notification::make()->title('Deposit reported')
                        ->body('Finance will confirm it once the money has been received.')->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title('Deposit not reported')
                        ->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionStatements::route('/'),
            'view' => ViewCommissionStatement::route('/{record}'),
        ];
    }
}
