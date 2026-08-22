<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TimberCategory;
use App\Services\SpeciesDirectoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpeciesIndexRequest extends FormRequest
{
    public const MAX_PER_PAGE = 48;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $facets = SpeciesDirectoryService::facetDefinitions();

        return [
            'q' => ['nullable', 'string', 'max:160'],
            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*' => [Rule::in(array_column(TimberCategory::cases(), 'value'))],
            'properties' => ['nullable', 'array', 'max:20'],
            'properties.*' => [Rule::in(array_keys($facets['properties']))],
            'applications' => ['nullable', 'array', 'max:20'],
            'applications.*' => [Rule::in(array_keys($facets['applications']))],
            'region' => ['nullable', 'string', 'max:120'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(array_keys(SpeciesDirectoryService::sortOptions()))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'q' => (string) $this->query('q', ''),
            'categories' => array_values(array_filter((array) $this->query('categories', []))),
            'properties' => array_values(array_filter((array) $this->query('properties', []))),
            'applications' => array_values(array_filter((array) $this->query('applications', []))),
            'region' => (string) $this->query('region', ''),
            'inStock' => $this->boolean('in_stock'),
            'sort' => (string) $this->query('sort', 'popularity'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->query('per_page') ?: 12);
    }
}
