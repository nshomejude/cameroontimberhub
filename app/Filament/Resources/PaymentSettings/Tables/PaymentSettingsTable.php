<?php

namespace App\Filament\Resources\PaymentSettings\Tables;

use App\Enums\PaymentProvider;
use App\Models\PaymentSetting;
use App\Services\Payments\ProviderFeeCalculator;
use App\Services\Payments\ProviderFees;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->badge(),
                TextColumn::make('environment')->badge(),
                IconColumn::make('is_live')->label('Live')->boolean(),
                TextColumn::make('credentials_status')
                    ->label('Credentials')
                    ->badge()
                    ->state(fn (PaymentSetting $record): string => $record->isConfigured()
                        ? 'Configured'
                        : (filled($record->credentials) ? 'Partial' : 'Not set'))
                    ->color(fn (string $state): string => match ($state) {
                        'Configured' => 'success',
                        'Partial' => 'warning',
                        default => 'gray',
                    }),
                // Effective fee (DB override, else env default) — see
                // App\Services\Payments\ProviderFees.
                TextColumn::make('effective_fee')
                    ->label('Processing fee')
                    ->state(function (PaymentSetting $record): string {
                        $provider = PaymentProvider::tryFrom((string) $record->provider);

                        if ($provider === null) {
                            return '—';
                        }

                        $fees = ProviderFees::for($provider);
                        $source = ($record->fee_percent !== null || $record->fee_fixed !== null || filled($record->fee_bearer)) ? 'admin' : 'env';

                        return "{$fees['percent']}% + {$fees['fixed']} {$fees['currency']} · {$fees['bearer']} pays · {$source}";
                    }),
                TextColumn::make('updatedBy.name')->label('Last changed by')->placeholder('—'),
                TextColumn::make('updated_at')->label('Last changed')->dateTime('d M Y H:i')->sortable(),
            ])
            ->recordActions([
                // Fee settings are pricing, not secrets: a single
                // payments.manage admin may change them (no two-person gate,
                // unlike credentials). Every change is activity-logged with
                // the before/after values. A blank field falls back to env.
                Action::make('editFees')
                    ->label('Edit fees')
                    ->icon('heroicon-o-receipt-percent')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('payments.manage'))
                    ->fillForm(fn (PaymentSetting $record): array => [
                        'fee_percent' => $record->fee_percent,
                        'fee_fixed' => $record->fee_fixed,
                        'fee_bearer' => $record->fee_bearer,
                    ])
                    ->schema([
                        TextInput::make('fee_percent')
                            ->label('Percentage fee (%)')
                            ->numeric()->minValue(0)->maxValue(50)
                            ->helperText('e.g. 4.4 for 4.4%. Blank = env default.'),
                        TextInput::make('fee_fixed')
                            ->label('Fixed fee (provider currency)')
                            ->numeric()->minValue(0)
                            ->helperText('e.g. 0.30 for PayPal (USD). Blank = env default.'),
                        Select::make('fee_bearer')
                            ->label('Who pays the fee')
                            ->options([
                                ProviderFeeCalculator::BEARER_BUYER => 'Buyer (passed through, disclosed at checkout)',
                                ProviderFeeCalculator::BEARER_PLATFORM => 'Platform (absorbed)',
                            ])
                            ->placeholder('Env default'),
                    ])
                    ->action(function (PaymentSetting $record, array $data): void {
                        abort_unless((bool) auth()->user()?->can('payments.manage'), 403);

                        $before = $record->only(['fee_percent', 'fee_fixed', 'fee_bearer']);

                        $record->update([
                            'fee_percent' => filled($data['fee_percent'] ?? null) ? $data['fee_percent'] : null,
                            'fee_fixed' => filled($data['fee_fixed'] ?? null) ? $data['fee_fixed'] : null,
                            'fee_bearer' => filled($data['fee_bearer'] ?? null) ? $data['fee_bearer'] : null,
                            'updated_by' => auth()->id(),
                        ]);

                        activity('payment_setting')
                            ->performedOn($record)
                            ->causedBy(auth()->user())
                            ->event('fees_updated')
                            ->withProperties(['before' => $before, 'after' => $record->only(['fee_percent', 'fee_fixed', 'fee_bearer'])])
                            ->log("Payment provider fees updated for {$record->provider}");

                        Notification::make()->title('Provider fees updated')->success()->send();
                    }),
            ])
            ->defaultSort('provider')
            ->paginated(false);
    }
}
