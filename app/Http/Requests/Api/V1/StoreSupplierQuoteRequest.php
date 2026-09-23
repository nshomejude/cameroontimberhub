<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A supplier's quote submission over the API — mirrors the fields
 * `Filament\Exporter\Resources\Quotes\Schemas\QuoteForm` collects, minus
 * everything the server derives (`company_id`, `rfq_company_id`, `status`,
 * `reference_code`, every line total, the subtotal and the total —
 * QuoteService::recalculate() is the only source of those, never the
 * request body).
 *
 * `items` requires at least one row, same as QuoteService::submit()'s own
 * guard ("A quote needs at least one line item...") — validating it here
 * turns that into a 422 with a field key instead of a 500/generic 409.
 */
class StoreSupplierQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['nullable', Rule::enum(RfqCurrency::class)],
            'incoterm' => ['nullable', Rule::enum(RfqIncoterm::class)],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.species_id' => ['nullable', 'integer', 'exists:species,id'],
            'items.*.form' => ['nullable', Rule::enum(TimberForm::class)],
            'items.*.grade' => ['nullable', 'string', 'max:60'],
            'items.*.dimensions' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', Rule::enum(RfqUnit::class)],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
