<?php

namespace App\Filament\Resources\Claims\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClaimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Claim')
                    ->columns(2)
                    ->schema([
                        Textarea::make('claim_text')
                            ->label('Claim text')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('page_location')
                            ->label('Page location')
                            ->maxLength(255)
                            ->helperText('Where on the site this claim appears, e.g. "Homepage hero stats".'),
                        Select::make('owner_id')
                            ->label('Owner')
                            ->relationship('owner', 'name')
                            ->searchable()
                            ->native(false),
                        Textarea::make('evidence_source')
                            ->label('Evidence source')
                            ->rows(3)
                            ->helperText('How the claim is substantiated, e.g. a real DB query, or "Static claim, no live data source".')
                            ->columnSpanFull(),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft' => 'Draft',
                                'approved' => 'Approved',
                                'needs_review' => 'Needs review',
                                'retired' => 'Retired',
                            ])
                            ->default('draft')
                            ->native(false),
                        DatePicker::make('approved_at')->label('Approval date'),
                        DatePicker::make('review_date')->label('Review date'),
                    ]),
            ]);
    }
}
