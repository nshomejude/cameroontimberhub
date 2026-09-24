<?php

namespace App\Filament\Resources\Announcements\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(160)->columnSpanFull(),
                        Textarea::make('body')->rows(3)->columnSpanFull(),
                        TextInput::make('image_path')
                            ->helperText('Relative path under public/img/, e.g. "promo/harvest.jpg".')
                            ->maxLength(255),
                        TextInput::make('cta_label')->maxLength(60),
                        TextInput::make('cta_screen')
                            ->label('CTA screen id')
                            ->maxLength(3)
                            ->helperText('3-digit mobile app screen id, e.g. "047". Not a web URL.'),
                        TextInput::make('cta_reference')
                            ->label('CTA reference')
                            ->helperText('Optional id/slug the target screen needs.')
                            ->maxLength(255),
                        DateTimePicker::make('starts_at')->helperText('Blank = live immediately.'),
                        DateTimePicker::make('ends_at')->helperText('Blank = open-ended.'),
                        Toggle::make('is_active')->default(true),
                        TextInput::make('sort_order')->numeric()->default(0),
                    ]),
            ]);
    }
}
