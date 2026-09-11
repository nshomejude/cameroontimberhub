<?php

namespace App\Filament\Resources\Credits\Pages;

use App\Enums\CreditSource;
use App\Filament\Resources\Credits\CreditResource;
use App\Models\Company;
use App\Services\Billing\CreditLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCredits extends ListRecords
{
    protected static string $resource = CreditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grantCredit')
                ->label('Grant credit')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    TextInput::make('amount')
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),
                    Select::make('currency')
                        ->options(['XAF' => 'XAF', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'CNY' => 'CNY'])
                        ->required(),
                    Textarea::make('reason')
                        ->rows(2)
                        ->required(),
                    Select::make('source')
                        ->options(array_combine(
                            array_map(fn (CreditSource $s) => $s->value, CreditSource::cases()),
                            array_map(fn (CreditSource $s) => $s->label(), CreditSource::cases()),
                        ))
                        ->default(CreditSource::AdminGrant->value)
                        ->required(),
                    DateTimePicker::make('expires_at')
                        ->helperText('Optional — blank means the credit never expires.'),
                ])
                ->action(function (array $data): void {
                    $company = Company::findOrFail($data['company_id']);

                    app(CreditLedger::class)->grant(
                        $company,
                        (string) $data['amount'],
                        $data['currency'],
                        $data['reason'],
                        CreditSource::from($data['source']),
                        auth()->user(),
                        $data['expires_at'] ?? null,
                    );

                    Notification::make()
                        ->title('Credit granted')
                        ->success()
                        ->send();
                }),
        ];
    }
}
