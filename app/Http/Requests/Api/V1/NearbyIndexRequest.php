<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OrganisationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NearbyIndexRequest extends FormRequest
{
    /** Company `type` values a buyer can search for as "sellers". */
    public const SELLER_TYPES = [
        OrganisationType::Processor->value,
        OrganisationType::Manufacturer->value,
        OrganisationType::Retailer->value,
        OrganisationType::Artisan->value,
        OrganisationType::Supplier->value,
    ];

    public const DEFAULT_RADIUS_KM = 100;

    public const MAX_RADIUS_KM = 1000;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'types' => ['nullable', 'array', 'max:5'],
            'types.*' => ['string', Rule::in(self::SELLER_TYPES)],
            'radius_km' => ['nullable', 'numeric', 'gt:0', 'max:'.self::MAX_RADIUS_KM],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function lat(): float
    {
        return (float) $this->validated('lat');
    }

    public function lng(): float
    {
        return (float) $this->validated('lng');
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_values(array_unique((array) ($this->validated('types') ?? [])));
    }

    public function radiusKm(): float
    {
        return (float) ($this->validated('radius_km') ?? self::DEFAULT_RADIUS_KM);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
