<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A supplier's product edit over the API — the `PATCH` counterpart of
 * {@see StoreSupplierProductRequest}. Every field is `sometimes` (a PATCH may
 * touch only part of the record), but a field that IS present is validated
 * with the exact same rule the web form applies, including the
 * charcoal/species conditional.
 */
class UpdateSupplierProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $productType = $this->input('product_type');
        $isCharcoal = $productType === ProductType::Charcoal->value;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'product_type' => ['sometimes', 'required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'species_id' => [
                $this->has('product_type') && $isCharcoal ? 'nullable' : 'sometimes',
                'nullable', 'integer', 'exists:species,id',
            ],
            'grade' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],

            'price_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_currency' => ['sometimes', 'nullable', 'string', 'max:3'],
            'price_unit' => ['sometimes', 'nullable', Rule::in(array_column(PriceUnit::cases(), 'value'))],
            'moq_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'moq_unit' => ['sometimes', 'nullable', Rule::in(array_column(PriceUnit::cases(), 'value'))],

            'thickness_mm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'width_min_mm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'width_max_mm' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'length_min_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'length_max_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'moisture_content' => ['sometimes', 'nullable', 'string', 'max:60'],
            'origin' => ['sometimes', 'nullable', 'string', 'max:120'],
            'certification' => ['sometimes', 'nullable', 'string', 'max:150'],

            'specifications' => ['sometimes', 'nullable', 'array'],
            'key_benefits' => ['sometimes', 'nullable', 'array'],
            'materials_used' => ['sometimes', 'nullable', 'string', 'max:255'],
            'finish' => ['sometimes', 'nullable', 'string', 'max:120'],
            'dimensions_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_attributes' => ['sometimes', 'nullable', 'array'],

            'status' => ['sometimes', 'required', Rule::in(array_column(ProductStatus::cases(), 'value'))],
        ];
    }
}
