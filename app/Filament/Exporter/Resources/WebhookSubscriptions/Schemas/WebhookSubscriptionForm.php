<?php

namespace App\Filament\Exporter\Resources\WebhookSubscriptions\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WebhookSubscriptionForm
{
    /**
     * Kept in sync by hand with App\Jobs\RelayOutboxEventsJob::EVENT_MAP —
     * the only event types the outbox relay will ever dispatch a delivery
     * for, so this is deliberately the same fixed list rather than a
     * free-text field.
     */
    public const EVENT_TYPES = [
        'order.awarded' => 'Order awarded',
        'checkpoint.recorded' => 'Shipment checkpoint recorded',
        'compliance_case.opened' => 'Compliance case opened',
    ];

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
                            ->options(self::EVENT_TYPES)
                            ->required()
                            ->columns(1),
                    ]),
            ]);
    }
}
