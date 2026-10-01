<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Domain\Catalog\ProductPublishingRules;
use App\Services\SupplierApiScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A supplier's new catalogue listing over the API — mirrors the fields and
 * rules `Filament\Exporter\Resources\Products\Schemas\ProductForm` collects,
 * minus `company_id` (server-derived, exactly as
 * `CreateProduct::mutateFormDataBeforeCreate()` does it on the web).
 *
 * `species_id` is required unless `product_type` is `charcoal` — the same
 * `Get $get` closure the web form uses
 * (`->required(fn (Get $get) => $get('product_type') !== ProductType::Charcoal->value)`).
 *
 * Fields the web form leaves free-text with no enum/Select behind them
 * (`price_currency`, `certification`, `grade`) stay free-text here too —
 * there is no dropdown to validate against because the web form has none.
 */
class StoreSupplierProductRequest extends FormRequest
{
    /**
     * Only owner/manager members may write listings — see
     * {@see ProductPublishingRules::canManageProducts()}.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ProductPublishingRules::canManageProducts($user, app(SupplierApiScope::class)->company($user));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCharcoal = $this->input('product_type') === ProductType::Charcoal->value;

        return [
            'name' => ['required', 'string', 'max:200'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'species_id' => [$isCharcoal ? 'nullable' : 'required', 'nullable', 'integer', 'exists:species,id'],
            'grade' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string'],

            'price_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'price_currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'price_unit' => ['nullable', Rule::in(array_column(PriceUnit::cases(), 'value'))],
            'moq_quantity' => ['nullable', 'numeric', 'min:0'],
            'moq_unit' => ['nullable', Rule::in(array_column(PriceUnit::cases(), 'value'))],

            'thickness_mm' => ['nullable', 'numeric', 'min:0'],
            'width_min_mm' => ['nullable', 'numeric', 'min:0'],
            'width_max_mm' => ['nullable', 'numeric', 'min:0', Rule::when($this->filled('width_min_mm'), 'gte:width_min_mm')],
            'length_min_m' => ['nullable', 'numeric', 'min:0'],
            'length_max_m' => ['nullable', 'numeric', 'min:0', Rule::when($this->filled('length_min_m'), 'gte:length_min_m')],
            'moisture_content' => ['nullable', 'string', 'max:60'],
            'origin' => ['nullable', 'string', 'max:120'],
            'certification' => ['nullable', 'string', 'max:150'],

            'specifications' => ['nullable', 'array'],
            'key_benefits' => ['nullable', 'array'],
            'materials_used' => ['nullable', 'string', 'max:255'],
            'finish' => ['nullable', 'string', 'max:120'],
            'dimensions_description' => ['nullable', 'string', 'max:255'],
            'custom_attributes' => ['nullable', 'array'],

            // Defaults to Draft server-side when absent, same as the form's
            // `->default(ProductStatus::Draft->value)`; Active is allowed
            // here too, exactly as the web form allows creating straight
            // into Active (CreateProduct routes it through PublishProductCommand).
            'status' => ['nullable', Rule::in(array_column(ProductStatus::cases(), 'value'))],
        ];
    }
}
