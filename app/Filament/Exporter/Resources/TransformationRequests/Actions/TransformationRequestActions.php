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
            self::make('accept', __('messages.transformation_actions.accept'), 'heroicon-m-check', 'success', fn (TransformationRequestService $s, TransformationRequest $r) => $s->accept(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('quote', __('messages.transformation_actions.send_quote'), 'heroicon-m-currency-dollar', 'primary', fn (TransformationRequestService $s, TransformationRequest $r, array $data) => $s->quote(auth()->user(), $r, $data))
                ->schema([
                    TextInput::make('amount')->label(__('messages.transformation_actions.amount'))->numeric()->minValue(0.01)->required(),
                    Select::make('currency')->label(__('messages.transformation_actions.currency'))->options(array_combine(TransformationRequestService::QUOTE_CURRENCIES, TransformationRequestService::QUOTE_CURRENCIES))->default('XAF')->required(),
                    TextInput::make('lead_time_days')->label(__('messages.transformation_actions.lead_time_days'))->integer()->minValue(0),
                    Textarea::make('notes')->label(__('messages.transformation_actions.notes'))->maxLength(2000),
                ]),

            self::make('decline', __('messages.transformation_actions.decline'), 'heroicon-m-x-mark', 'danger', fn (TransformationRequestService $s, TransformationRequest $r, array $data) => $s->decline(auth()->user(), $r, (string) $data['reason']))
                ->schema([Textarea::make('reason')->label(__('messages.transformation_actions.reason'))->required()->maxLength(1000)]),

            self::make('startJob', __('messages.transformation_actions.start_job'), 'heroicon-m-play', 'primary', fn (TransformationRequestService $s, TransformationRequest $r) => $s->startJob(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('completeJob', __('messages.transformation_actions.complete_job'), 'heroicon-m-check-badge', 'success', fn (TransformationRequestService $s, TransformationRequest $r, array $data) => $s->completeJob(
                auth()->user(),
                $r,
                filled($data['input_volume_m3'] ?? null) ? (float) $data['input_volume_m3'] : null,
                filled($data['output_volume_m3'] ?? null) ? (float) $data['output_volume_m3'] : null,
                $data['notes'] ?? null,
            ))
                ->schema([
                    TextInput::make('input_volume_m3')->label(__('messages.transformation_actions.input_volume_m3'))->numeric()->minValue(0.01)
                        ->helperText(__('messages.transformation_actions.input_volume_help')),
                    TextInput::make('output_volume_m3')->label(__('messages.transformation_actions.output_volume_m3'))->numeric()->minValue(0.01)
                        ->helperText(__('messages.transformation_actions.output_volume_help')),
                    Textarea::make('notes')->label(__('messages.transformation_actions.notes'))->maxLength(2000),
                ]),

            self::make('acceptQuote', __('messages.transformation_actions.accept_quote'), 'heroicon-m-check', 'success', fn (TransformationRequestService $s, TransformationRequest $r) => $s->acceptQuote(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('declineQuote', __('messages.transformation_actions.decline_quote'), 'heroicon-m-x-mark', 'danger', fn (TransformationRequestService $s, TransformationRequest $r) => $s->declineQuote(auth()->user(), $r))
                ->requiresConfirmation(),

            self::make('cancel', __('messages.transformation_actions.cancel_request'), 'heroicon-m-no-symbol', 'gray', fn (TransformationRequestService $s, TransformationRequest $r) => $s->cancel(auth()->user(), $r))
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
                    Notification::make()->title(__('messages.transformation_actions.done', ['action' => $label]))->success()->send();
                } catch (ApiException|HttpException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
