<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductType;
use App\Services\ProductCatalogueService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marketplace query string. Same filter vocabulary the web catalogue uses,
 * spelled in snake_case for the JSON client and validated so an unknown sort
 * or an unbounded per_page is a clear 422 rather than a silent default.
 */
class ProductIndexRequest extends FormRequest
{
    public const MAX_PER_PAGE = 48;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'types' => ['nullable', 'array', 'max:20'],
            'types.*' => [Rule::in(array_column(ProductType::cases(), 'value'))],
            'species' => ['nullable', 'array', 'max:20'],
            'species.*' => ['string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'supplier' => ['nullable', 'string', 'max:160'],
            'certified' => ['nullable', 'boolean'],
            'best_sellers' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(array_keys(ProductCatalogueService::sortOptions()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /** The filter array ProductCatalogueService expects. @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'q' => (string) $this->query('q', ''),
            'types' => array_values(array_filter((array) $this->query('types', []))),
            'speciesIn' => array_values(array_filter((array) $this->query('species', []))),
            'region' => (string) $this->query('region', ''),
            'supplier' => (string) $this->query('supplier', ''),
            'certifiedOnly' => $this->boolean('certified'),
            'bestSellers' => $this->boolean('best_sellers'),
            'sort' => (string) $this->query('sort', 'featured'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->query('per_page') ?: 12);
    }
}
