<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\OrganisationType;
use App\Enums\PriceUnit;
use App\Enums\ProductType;
use App\Enums\RfqCurrency;
use App\Enums\SupplierType;
use App\Http\Controllers\Controller;
use App\Models\Species;
use App\Support\CameroonGeography;
use Illuminate\Http\JsonResponse;

/**
 * Agent Ingestion Gateway — everything an agent needs to map scraped data
 * onto our vocabularies: species (id + slug + names), product types, price
 * and MOQ units, currencies, regions/cities, supplier and organisation types.
 *
 * @tags Agent ingestion
 */
class AgentReferenceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $enum = fn (array $options) => collect($options)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return response()->json(['data' => [
            'species' => Species::query()
                ->orderBy('common_name')
                ->get(['id', 'slug', 'common_name', 'scientific_name', 'local_names', 'trade_names'])
                ->map(fn (Species $s) => [
                    'id' => $s->id,
                    'slug' => $s->slug,
                    'common_name' => $s->common_name,
                    'scientific_name' => $s->scientific_name,
                    'local_names' => $s->local_names,
                    'trade_names' => $s->trade_names,
                ]),
            'product_types' => $enum(ProductType::options()),
            'price_units' => $enum(PriceUnit::options()),
            'moq_units' => $enum(PriceUnit::options()),
            'currencies' => RfqCurrency::values(),
            'supplier_types' => $enum(SupplierType::options()),
            'organisation_types' => $enum(OrganisationType::options()),
            'regions' => collect(CameroonGeography::regions())
                ->map(fn (array $r, string $name) => ['name' => $name, 'cities' => $r['cities']])
                ->values(),
            'country_code_default' => 'CM',
            'limits' => [
                'batch_max_suppliers' => AgentSupplierController::BATCH_MAX_SUPPLIERS,
                'batch_max_products_per_supplier' => AgentSupplierController::BATCH_MAX_PRODUCTS,
                'image_max_kb' => 5120,
                'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
            ],
        ]]);
    }
}
