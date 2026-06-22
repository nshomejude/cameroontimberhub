<?php

namespace App\Filament\Exporter\Resources\CompanyDocuments\Schemas;

use App\Models\DocumentType;
use App\Services\DocumentService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Upload a compliance document')
                    ->columns(2)
                    ->schema([
                        Select::make('document_type_id')
                            ->label('Document type')
                            ->relationship('documentType', 'name')
                            ->required()->searchable()->preload()->live(),

                        FileUpload::make('storage_path')
                            ->label('File')
                            ->disk(DocumentService::DISK)
                            ->directory('companies/documents')
                            ->visibility('private')
                            ->storeFileNamesIn('original_filename')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(10240)
                            ->required()
                            ->columnSpanFull(),

                        DatePicker::make('issue_date'),
                        DatePicker::make('expiry_date')
                            ->helperText('Required at approval for permits and certificates.'),

                        KeyValue::make('sigif_fields')
                            ->label('SIGIF reference fields')
                            ->keyLabel('Field')->valueLabel('Value')
                            ->visible(fn (callable $get): bool => (bool) DocumentType::find($get('document_type_id'))?->supports_sigif)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
