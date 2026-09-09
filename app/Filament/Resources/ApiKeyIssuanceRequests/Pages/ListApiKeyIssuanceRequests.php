<?php

namespace App\Filament\Resources\ApiKeyIssuanceRequests\Pages;

use App\Actions\ApiKeys\RequestApiKeyIssuance;
use App\Filament\Resources\ApiKeyIssuanceRequests\ApiKeyIssuanceRequestResource;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListApiKeyIssuanceRequests extends ListRecords
{
    protected static string $resource = ApiKeyIssuanceRequestResource::class;

    /** Available Sanctum abilities for `/api/v1` product keys (Phase 0 baseline). */
    public const ABILITIES = [
        'products:read' => 'Read products',
        'species:read' => 'Read species',
        'suppliers:read' => 'Read suppliers',
        'rfqs:read' => 'Read RFQs',
        'rfqs:write' => 'Create/manage RFQs',
        'quotes:read' => 'Read quotes',
        'quotes:write' => 'Accept/decline quotes',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestKeyIssuance')
                ->label('Request new API key')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    TextInput::make('requested_name')
                        ->label('Key name')
                        ->required(),
                    CheckboxList::make('requested_abilities')
                        ->label('Abilities')
                        ->options(self::ABILITIES)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $company = Company::findOrFail($data['company_id']);

                    $result = app(RequestApiKeyIssuance::class)->execute(
                        $company,
                        $data['requested_name'],
                        $data['requested_abilities'],
                        auth()->user(),
                    );

                    // Shown ONCE — this token is never retrievable again.
                    // Relay it to a different admin out-of-band so they can
                    // approve the issuance below.
                    Notification::make()
                        ->title('Key issuance requested — invite token (shown once)')
                        ->body('Give this token to a DIFFERENT admin so they can approve the issuance: '.$result['invite_token'])
                        ->warning()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
