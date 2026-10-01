<?php

namespace App\Filament\Exporter\Pages;

use App\Enums\RfqType;
use App\Filament\Exporter\Concerns\ShowsBuyerResponseLockBanner;
use App\Filament\Exporter\Resources\Quotes\QuoteResource;
use App\Models\Company;
use App\Models\Rfq;
use App\Services\RfqMatchingService;
use App\Services\RfqOpenRequestService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * "Buyer requests" — the supplier's open-requests board: approved RFQs that
 * match this company (RfqMatchingService::openRequestsFor()) but were not
 * routed to it. Buyer identity/contact is never shown here; "Quote this
 * request" self-routes the RFQ (RfqOpenRequestService::selfRoute()) and opens
 * the normal Create Quote form with it preselected.
 */
class BuyerRequests extends Page implements HasTable
{
    use InteractsWithTable;
    use ShowsBuyerResponseLockBanner;

    protected string $view = 'filament.exporter.pages.buyer-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Buyer requests';

    protected static ?string $title = 'Open buyer requests';

    protected static ?int $navigationSort = 2;

    public function getCompany(): ?Company
    {
        return auth()->user()?->companies()->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $company = $this->getCompany();

                return $company
                    ? app(RfqMatchingService::class)->openRequestsFor($company)->with('items.species')
                    : Rfq::query()->whereRaw('1 = 0');
            })
            ->emptyStateHeading('No open buyer requests match your company right now')
            ->emptyStateDescription('Requests appear here when they match the species and activity on your profile. Your plan must include "Receive RFQ leads" and your company must be verified or pending verification.')
            ->columns([
                TextColumn::make('reference_code')->label('Reference'),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (?RfqType $state): string => $state?->label() ?? RfqType::Export->label()),
                TextColumn::make('title')->placeholder('—')->wrap(),
                TextColumn::make('items_summary')->label('Items')
                    ->state(fn (Rfq $record): string => $record->items->map(fn ($i) => $i->label())->implode('; '))
                    ->wrap(),
                TextColumn::make('destination_country_code')->label('Destination')->placeholder('—'),
                TextColumn::make('deadline')->date('d M Y')->placeholder('—'),
                TextColumn::make('created_at')->label('Posted')->date('d M Y'),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(RfqType::cases())->mapWithKeys(fn (RfqType $t) => [$t->value => $t->label()])->all()),
            ])
            ->recordActions([
                Action::make('quote')
                    ->label('Quote this request')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->disabled(fn (): bool => static::buyerResponsesLocked())
                    ->tooltip(fn (): ?string => static::buyerResponsesLocked() ? Company::VERIFICATION_REQUIRED_MESSAGE : null)
                    ->action(function (Rfq $record) {
                        $company = $this->getCompany();

                        // Server-side too: a disabled button is not a guard.
                        if ($company !== null && ! $company->canRespondToBuyers()) {
                            Notification::make()->title(Company::VERIFICATION_REQUIRED_MESSAGE)->warning()->send();

                            return null;
                        }

                        if (! $company || ! app(RfqOpenRequestService::class)->selfRoute($record, $company, auth()->user())) {
                            Notification::make()->title('This request is no longer available to your company.')->danger()->send();

                            return null;
                        }

                        return redirect(QuoteResource::getUrl('create', ['rfq' => $record->getKey()]));
                    }),
            ]);
    }
}
