<?php

namespace App\Filament\Resources\AiApiKeyChangeRequests\Pages;

use App\Actions\Ai\RequestAiApiKeyChange;
use App\Enums\AiProvider;
use App\Filament\Resources\AiApiKeyChangeRequests\AiApiKeyChangeRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAiApiKeyChangeRequests extends ListRecords
{
    protected static string $resource = AiApiKeyChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestKeyChange')
                ->label('Propose new API key')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('provider')
                        ->options(collect(AiProvider::cases())->mapWithKeys(fn (AiProvider $p) => [$p->value => $p->label()]))
                        ->required(),
                    TextInput::make('new_api_key')
                        ->label('New API key')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(RequestAiApiKeyChange::class)->execute(
                        AiProvider::from($data['provider']),
                        $data['new_api_key'],
                        auth()->user(),
                    );

                    // Shown ONCE — this token is never retrievable again.
                    // Relay it to a different admin out-of-band so they can
                    // approve the change below.
                    Notification::make()
                        ->title('Key change requested — invite token (shown once)')
                        ->body('Give this token to a DIFFERENT admin so they can approve the change: '.$result['invite_token'])
                        ->warning()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
