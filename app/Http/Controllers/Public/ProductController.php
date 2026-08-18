<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductCatalogueService;
use App\Services\RfqList;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private readonly ProductCatalogueService $catalogue) {}

    public function index(Request $request): View
    {
        // The Livewire component owns the interactive view; the controller
        // renders the same query once, server-side, so the ItemList structured
        // data always describes what the visitor actually sees.
        $filters = [
            'q' => (string) $request->query('q', ''),
            'types' => array_filter((array) $request->query('types', $request->query('type', []))),
            'speciesIn' => array_filter((array) $request->query('wood', $request->query('species', []))),
            'region' => (string) $request->query('region', ''),
            'certifiedOnly' => $request->boolean('certified'),
            'bestSellers' => $request->boolean('best'),
            'sort' => (string) $request->query('sort', 'featured'),
        ];

        $products = $this->catalogue->search($filters, 12);

        $breadcrumbs = [
            ['label' => 'Home', 'url' => route('home')],
            ['label' => 'Marketplace', 'url' => route('marketplace')],
        ];

        return view('public.products.index', [
            'breadcrumbs' => $breadcrumbs,
            'schema' => [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => 'Cameroon timber marketplace listings',
                'numberOfItems' => $products->total(),
                'itemListElement' => $products->values()->map(fn (Product $p, int $i) => [
                    '@type' => 'ListItem',
                    'position' => $products->firstItem() + $i,
                    'url' => route('products.show', $p->slug),
                    'name' => $p->name,
                ])->all(),
            ],
        ]);
    }

    public function show(string $slug, RfqList $rfqList): View
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active)
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->with([
                'company.activeBadges',
                'company.exportMarkets',
                'company.species:id,slug,common_name',
                'species',
                'images',
            ])
            ->firstOrFail();

        $productsBySupplier = Product::query()
            ->active()
            ->where('company_id', $product->company_id)
            ->count();

        // Same species first, then the same processing form — never padded out
        // with unrelated stock, and always inside the public visibility gate.
        $similar = Product::query()
            ->active()
            ->whereKeyNot($product->getKey())
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->where(function ($q) use ($product) {
                $q->when($product->species_id, fn ($s) => $s->orWhere('species_id', $product->species_id))
                    ->orWhere('product_type', $product->product_type->value);
            })
            ->with(['company:id,slug,legal_name,trade_name,status,logo_path', 'species:id,slug,common_name', 'images'])
            ->orderByRaw('CASE WHEN species_id = ? THEN 0 ELSE 1 END', [$product->species_id])
            ->orderByDesc('is_best_seller')
            ->orderByDesc('is_featured')
            ->limit(5)
            ->get();

        return view('public.products.show', [
            'product' => $product,
            'similar' => $similar,
            'supplierProductCount' => $productsBySupplier,
            'inRfqList' => $rfqList->has($product),
            'rfqListCount' => $rfqList->count(),
            'breadcrumbs' => $this->breadcrumbs($product),
            'schema' => $this->schema($product),
        ]);
    }

    /** @return list<array{label: string, url?: string}> */
    private function breadcrumbs(Product $product): array
    {
        return [
            ['label' => 'Home', 'url' => route('home')],
            ['label' => 'Marketplace', 'url' => route('marketplace')],
            ['label' => $product->product_type->label(), 'url' => route('marketplace', ['type' => $product->product_type->value])],
            ['label' => $product->name, 'url' => route('products.show', $product->slug)],
        ];
    }

    /** @return array<string, mixed> */
    private function schema(Product $product): array
    {
        $supplier = $product->company;

        $seller = array_filter([
            '@type' => 'Organization',
            'name' => $supplier?->name,
            'url' => $supplier ? route('companies.show', $supplier->slug) : null,
            'logo' => $supplier?->logoUrl(),
            'address' => $supplier?->city ? [
                '@type' => 'PostalAddress',
                'addressLocality' => $supplier->city,
                'addressRegion' => $supplier->region,
                'addressCountry' => 'CM',
            ] : null,
        ], fn ($v) => $v !== null);

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'sku' => $product->slug,
            'url' => route('products.show', $product->slug),
            'description' => str(strip_tags((string) $product->description))->limit(300)->value(),
            'category' => $product->product_type->label(),
            'material' => $product->species?->common_name,
            'countryOfOrigin' => $product->origin,
            'image' => array_column($product->galleryImages(), 'url') ?: null,
            'brand' => $seller,
        ], fn ($v) => $v !== null && $v !== '');

        // The seller is the verified supplier behind the listing.
        $schema['offers'] = array_filter([
            '@type' => 'Offer',
            'priceCurrency' => $product->price_currency,
            'price' => $product->price_amount !== null ? (string) $product->price_amount : null,
            'availability' => 'https://schema.org/InStock',
            'url' => route('products.show', $product->slug),
            'seller' => $seller,
            'eligibleQuantity' => $product->moq_quantity !== null ? [
                '@type' => 'QuantitativeValue',
                'minValue' => (float) $product->moq_quantity,
                'unitText' => $product->moq_unit->label(),
            ] : null,
        ], fn ($v) => $v !== null);

        // Emitted ONLY when a real rating with a real review count exists.
        if ($product->hasRating()) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $product->rating,
                'reviewCount' => (int) $product->reviews_count,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return $schema;
    }
}
