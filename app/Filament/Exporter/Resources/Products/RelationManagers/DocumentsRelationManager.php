<?php

namespace App\Filament\Exporter\Resources\Products\RelationManagers;

use App\Enums\DocumentVerificationStatus;
use App\Models\Document;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-product compliance documents (production-readiness plan Batch B, Task
 * B2). Attaches rows to the shared polymorphic `documents` store via the
 * Product's `documents` relation (HasDocuments trait).
 *
 * Company-scoping is inherited: the exporter ProductResource only ever
 * route-binds a product the acting user's company owns
 * (ProductResource::getRecordRouteBindingEloquentQuery), so a supplier
 * cannot open this relation manager for another company's product.
 *
 * `verification_status` is deliberately NOT a form field — staff verify
 * documents elsewhere; a supplier can only upload, not self-certify.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Compliance documents';

    /**
     * Authorization here is by parent ownership, not the staff-oriented
     * DocumentPolicy (which gates the internal review workflow). The exporter
     * ProductResource only ever binds a product the acting user's company
     * owns, so any supplier reaching this manager may manage that product's
     * own documents.
     */
    protected function canCreate(): bool
    {
        return true;
    }

    protected function canEdit(Model $record): bool
    {
        return true;
    }

    protected function canDelete(Model $record): bool
    {
        return true;
    }

    protected function canDeleteAny(): bool
    {
        return true;
    }

    protected function getCreateAuthorizationResponse(): Response
    {
        return Response::allow();
    }

    protected function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::allow();
    }

    protected function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::allow();
    }

    protected function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::allow();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Document type')
                    ->required()
                    ->options([
                        'datasheet' => 'Product datasheet',
                        'legal_origin' => 'Proof of legal origin',
                        'fsc_pefc' => 'FSC / PEFC certificate',
                        'phytosanitary' => 'Phytosanitary certificate',
                        'other' => 'Other',
                    ]),

                FileUpload::make('storage_path')
                    ->label('File')
                    ->disk('documents')
                    ->directory('products/documents')
                    ->visibility('private')
                    ->storeFileNamesIn('original_filename')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240)
                    ->required()
                    ->columnSpanFull(),

                DatePicker::make('issued_at')->label('Issued on'),
                DatePicker::make('expires_at')->label('Expires on'),

                TextInput::make('issuer')
                    ->label('Issuing authority')
                    ->maxLength(190),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (Document $record): string => $record->typeLabel()),

                TextColumn::make('verification_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (DocumentVerificationStatus $state): string => $state->label())
                    ->color(fn (DocumentVerificationStatus $state): string => $state->color()),

                TextColumn::make('expires_at')
                    ->label('Expiry')
                    ->badge()
                    ->placeholder('No expiry')
                    ->color(fn (?Carbon $state): string => match (true) {
                        $state === null => 'gray',
                        $state->isPast() => 'danger',
                        $state->diffInDays(now()) <= 30 => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (?Carbon $state): string => match (true) {
                        $state === null => 'No expiry',
                        $state->isPast() => $state->format('d M Y').' · expired',
                        $state->diffInDays(now()) <= 30 => $state->format('d M Y').' · soon',
                        default => $state->format('d M Y'),
                    }),

                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
