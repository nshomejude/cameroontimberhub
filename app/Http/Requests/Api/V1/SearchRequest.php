<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductType;
use App\Services\SearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRequest extends FormRequest
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
            'type' => ['nullable', Rule::in(SearchService::RESULT_TYPES)],
            'species' => ['nullable', 'string', 'max:120'],
            'product_type' => ['nullable', Rule::in(array_column(ProductType::cases(), 'value'))],
            'grade' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(array_keys(SearchService::searchSortOptions()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'q' => (string) $this->query('q', ''),
            'type' => (string) $this->query('type', 'products'),
            'species' => (string) $this->query('species', ''),
            'product_type' => (string) $this->query('product_type', ''),
            'grade' => (string) $this->query('grade', ''),
            'country' => (string) $this->query('country', ''),
            'sort' => (string) $this->query('sort', 'relevance'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->query('per_page') ?: 12);
    }
}
