<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Species;
use App\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['q', 'species', 'type']);

        return view('public.products.index', [
            'products' => $this->search->searchProducts($filters, 12),
            'speciesOptions' => Species::published()->orderBy('common_name')->get(['id', 'slug', 'common_name']),
            'filters' => $filters,
        ]);
    }

    public function show(string $slug): View
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active)
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->with(['company.activeBadges', 'species', 'images'])
            ->firstOrFail();

        $similar = Product::query()
            ->active()
            ->whereKeyNot($product->getKey())
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->when($product->species_id, fn ($q) => $q->where('species_id', $product->species_id))
            ->with(['company:id,slug,legal_name,trade_name', 'species:id,slug,common_name'])
            ->limit(5)
            ->get();

        return view('public.products.show', [
            'product' => $product,
            'similar' => $similar,
            'schema' => $this->schema($product),
        ]);
    }

    /** @return array<string, mixed> */
    private function schema(Product $product): array
    {
        $supplier = $product->company;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'url' => route('products.show', $product->slug),
            'description' => str(strip_tags((string) $product->description))->limit(300)->value(),
            'category' => $product->product_type->label(),
            'brand' => [
                '@type' => 'Organization',
                'name' => $supplier?->trade_name ?: $supplier?->legal_name,
            ],
        ];

        if ($product->price_amount !== null) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => (string) $product->price_amount,
                'priceCurrency' => $product->price_currency,
                'availability' => 'https://schema.org/InStock',
                'url' => route('products.show', $product->slug),
            ];
        }

        if ($product->rating !== null && $product->reviews_count > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $product->rating,
                'reviewCount' => $product->reviews_count,
            ];
        }

        return $schema;
    }
}
