<?php

namespace App\Filament\Exporter\Resources\TransformationRequests\Actions;

use App\Exceptions\Api\ApiException;
use App\Models\TransformationRequest;
use App\Services\TransformationRequestService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Row + header actions for the exporter Transformation Requests resource.
 * Each action is visible only when TransformationRequestService::actionsFor()
 * offers its key to the current user, and does nothing but call the matching
 * service method — the service owns every rule.
 */
class TransformationRequestActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::make('accept', 'Accept', 'heroicon-m-check', 'success', fn (TransformationRequestService $s, TransformationRequest $r) => $s->accept(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('quote', 'Send quote', 'heroicon-m-currency-dollar', 'primary', fn (TransformationRequestService $s, TransformationRequest $r, array $data) => $s->quote(auth()->user(), $r, $data))
                ->schema([
                    TextInput::make('amount')->numeric()->minValue(0.01)->required(),
                    Select::make('currency')->options(['XAF' => 'XAF', 'EUR' => 'EUR', 'USD' => 'USD'])->default('XAF')->required(),
                    TextInput::make('lead_time_days')->label('Lead time (days)')->integer()->minValue(0),
                    Textarea::make('notes')->maxLength(2000),
                ]),

            self::make('decline', 'Decline', 'heroicon-m-x-mark', 'danger', fn (TransformationRequestService $s, TransformationRequest $r, array $data) => $s->decline(auth()->user(), $r, (string) $data['reason']))
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(1000)]),

            self::make('startJob', 'Start job', 'heroicon-m-play', 'primary', fn (TransformationRequestService $s, TransformationRequest $r) => $s->startJob(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('completeJob', 'Complete job', 'heroicon-m-check-badge', 'success', fn (TransformationRequestService $s, TransformationRequest $r, array $data) => $s->completeJob(
                auth()->user(),
                $r,
                filled($data['input_volume_m3'] ?? null) ? (float) $data['input_volume_m3'] : null,
                filled($data['output_volume_m3'] ?? null) ? (float) $data['output_volume_m3'] : null,
                $data['notes'] ?? null,
            ))
                ->schema([
                    TextInput::make('input_volume_m3')->label('Input volume (m³)')->numeric()->minValue(0.01)
                        ->helperText('Leave blank to use the requested volume.'),
                    TextInput::make('output_volume_m3')->label('Output volume (m³)')->numeric()->minValue(0.01)
                        ->helperText('Leave blank for no reported loss.'),
                    Textarea::make('notes')->maxLength(2000),
                ]),

            self::make('acceptQuote', 'Accept quote', 'heroicon-m-check', 'success', fn (TransformationRequestService $s, TransformationRequest $r) => $s->acceptQuote(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('declineQuote', 'Decline quote', 'heroicon-m-x-mark', 'danger', fn (TransformationRequestService $s, TransformationRequest $r) => $s->declineQuote(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('cancel', 'Cancel request', 'heroicon-m-no-symbol', 'gray', fn (TransformationRequestService $s, TransformationRequest $r) => $s->cancel(auth()->user(), $r))
                ->requiresConfirmation(),
        ];
    }

    /** True when the service offers `$key` to the current user on `$record`. */
    public static function offers(string $key, ?TransformationRequest $record): bool
    {
        $user = auth()->user();

        if ($record === null || $user === null) {
            return false;
        }

        return collect(app(TransformationRequestService::class)->actionsFor($user, $record))
            ->contains('key', $key);
    }

    private static function make(string $key, string $label, string $icon, string $color, Closure $call): Action
    {
        return Action::make($key)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (?TransformationRequest $record): bool => self::offers($key, $record))
            ->action(function (TransformationRequest $record, array $data = []) use ($call, $label): void {
                try {
                    $call(app(TransformationRequestService::class), $record, $data);
                    Notification::make()->title("{$label}: done")->success()->send();
                } catch (ApiException|HttpException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
