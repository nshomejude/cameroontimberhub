<?php

namespace App\Filament\Exporter\Resources\WebhookSubscriptions\Schemas;

use App\Jobs\RelayOutboxEventsJob;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class WebhookSubscriptionForm
{
    /**
     * The subscribable event types, derived from the single canonical
     * registry (App\Jobs\RelayOutboxEventsJob::EVENT_MAP) — never a
     * hand-maintained second copy that could drift. Labels are generated
     * from the event_type string ("order.awarded" -> "Order awarded").
     *
     * @return array<string, string>
     */
    public static function eventTypeOptions(): array
    {
        return collect(RelayOutboxEventsJob::subscribableEventTypes())
            ->mapWithKeys(fn (string $type): array => [
                $type => Str::of($type)->replace(['.', '_'], ' ')->ucfirst()->toString(),
            ])
            ->all();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Endpoint')
                    ->columns(2)
                    ->schema([
                        TextInput::make('url')
                            ->label('Webhook URL')
                            ->url()
                            ->required()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Section::make('Events')
                    ->schema([
                        CheckboxList::make('event_types')
                            ->label('Subscribed events')
                            ->options(self::eventTypeOptions())
                            ->required()
                            ->columns(1),
                    ]),
            ]);
    }
}
