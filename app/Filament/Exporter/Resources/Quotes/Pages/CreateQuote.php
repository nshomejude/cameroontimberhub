<?php

namespace App\Filament\Exporter\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Exporter\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\Rfq;
use App\Services\QuoteReferenceGenerator;
use App\Services\QuoteService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * Ownership, eligibility and the reference code are decided server-side —
     * the form never supplies company_id, status or any money total.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();
        $rfq = Rfq::findOrFail($data['rfq_id']);

        try {
            $routing = app(QuoteService::class)->assertQuotable($rfq, $company);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['rfq_id' => $e->getMessage()]);
        }

        $data['company_id'] = $company->getKey();
        $data['rfq_company_id'] = $routing->getKey();
        $data['status'] = QuoteStatus::Draft->value;
        $data['reference_code'] = app(QuoteReferenceGenerator::class)->generate();

        // Totals are always derived; anything posted for them is discarded.
        unset($data['subtotal_amount'], $data['total_amount']);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(QuoteService::class)->recalculate($this->record instanceof Quote ? $this->record : Quote::findOrFail($this->record->getKey()));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
