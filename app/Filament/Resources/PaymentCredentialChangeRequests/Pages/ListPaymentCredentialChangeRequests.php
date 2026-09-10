<?php

namespace App\Filament\Resources\PaymentCredentialChangeRequests\Pages;

use App\Actions\Payments\RequestPaymentCredentialChange;
use App\Enums\PaymentProvider;
use App\Filament\Resources\PaymentCredentialChangeRequests\PaymentCredentialChangeRequestResource;
use App\Models\PaymentSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPaymentCredentialChangeRequests extends ListRecords
{
    protected static string $resource = PaymentCredentialChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestCredentialChange')
                ->label('Propose credential change')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('provider')
                        ->options(collect(PaymentProvider::cases())->mapWithKeys(fn (PaymentProvider $p) => [$p->value => $p->label()]))
                        ->required()
                        ->live()
                        ->helperText(fn (?string $state): string => $state
                            ? 'Required keys: '.implode(', ', PaymentSetting::REQUIRED_KEYS[$state] ?? [])
                            : ''),
                    Select::make('environment')
                        ->options([
                            'sandbox' => 'Sandbox',
                            'production' => 'Production',
                            'live' => 'Live',
                        ])
                        ->default('sandbox')
                        ->required(),
                    KeyValue::make('credentials')
                        ->label('Credentials')
                        ->keyLabel('Key')
                        ->valueLabel('Value')
                        ->helperText('Entered once. Values are encrypted at rest and never shown again.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(RequestPaymentCredentialChange::class)->execute(
                        PaymentProvider::from($data['provider']),
                        $data['credentials'] ?? [],
                        $data['environment'],
                        auth()->user(),
                    );

                    // Shown ONCE — this token is never retrievable again.
                    // Relay it to a different admin out-of-band so they can
                    // approve the change below.
                    Notification::make()
                        ->title('Credential change requested — invite token (shown once)')
                        ->body('Give this token to a DIFFERENT admin so they can approve the change: '.$result['invite_token'])
                        ->warning()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
