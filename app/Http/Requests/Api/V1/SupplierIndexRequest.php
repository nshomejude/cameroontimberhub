<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductType;
use App\Enums\SupplierType;
use App\Services\SearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierIndexRequest extends FormRequest
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
            'region' => ['nullable', 'string', 'max:120'],
            'species' => ['nullable', 'string', 'max:120'],
            'market' => ['nullable', 'string', 'max:2'],
            'types' => ['nullable', 'array', 'max:20'],
            'types.*' => [Rule::in(array_column(SupplierType::cases(), 'value'))],
            'specs' => ['nullable', 'array', 'max:20'],
            'specs.*' => [Rule::in(array_column(ProductType::cases(), 'value'))],
            'min_years' => ['nullable', 'integer', 'min:0', 'max:200'],
            'sort' => ['nullable', Rule::in(array_keys(SearchService::companySortOptions()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'q' => (string) $this->query('q', ''),
            'region' => (string) $this->query('region', ''),
            'species' => (string) $this->query('species', ''),
            'market' => (string) $this->query('market', ''),
            'types' => array_values(array_filter((array) $this->query('types', []))),
            'specs' => array_values(array_filter((array) $this->query('specs', []))),
            'minYears' => $this->query('min_years'),
            'sort' => (string) $this->query('sort', 'featured'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->query('per_page') ?: 12);
    }
}
