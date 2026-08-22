<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\Species;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public landing page. Everything the approved marketplace mockup shows —
 * featured products, popular species, verified suppliers and the platform
 * counters — is resolved from real records here so the design never renders
 * hardcoded marketing numbers when the catalogue has data.
 */
class HomeController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->active()
            ->featured()
            ->with(['company:id,slug,legal_name,trade_name,city,region,verified_at,status', 'species:id,slug,common_name'])
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->orderBy('id')
            ->limit(6)
            ->get();

        $species = Species::published()
            ->orderBy('sort_order')
            ->orderBy('common_name')
            ->limit(8)
            ->get(['id', 'slug', 'common_name']);

        $suppliers = Company::publiclyVisible()
            ->withCount(['products', 'exportMarkets'])
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->orderBy('id')
            ->limit(4)
            ->get();

        $stats = $this->stats();

        return view('home', [
            'products' => $products,
            'species' => $species,
            'suppliers' => $suppliers,
            'stats' => $stats,
            'categories' => $this->categories(),
            'speciesOptions' => $species,
            'typeOptions' => ProductType::options(),
            'faqs' => $this->faqs(),
            'schema' => $this->schema($products),
        ]);
    }

    /**
     * Platform counters for the hero panel / mobile stats band.
     *
     * These are public factual claims about the size of the marketplace, so
     * they report real aggregates only. The mockup's figures (200+ suppliers,
     * 5,000+ products, 1,200+ RFQs) were previously used as a floor via
     * max($actual, $floor), which meant the homepage advertised 200+ verified
     * suppliers while the platform had 13 -- a misrepresentation to buyers.
     * A tile with nothing behind it is dropped rather than padded.
     *
     * @return array<string, array{value: string, label: string}>
     */
    private function stats(): array
    {
        $suppliers = Company::publiclyVisible()->count();
        $products = Product::active()->whereHas('company', fn ($c) => $c->publiclyVisible())->count();
        $rfqs = class_exists(Rfq::class) ? Rfq::query()->count() : 0;
        $countries = CompanyExportMarket::query()
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->distinct()
            ->count('country_code');

        return array_filter([
            'suppliers' => $this->counter($suppliers, 'Verified Suppliers'),
            'products' => $this->counter($products, 'Timber Products'),
            'rfqs' => $this->counter($rfqs, 'RFQs Completed'),
            'countries' => $this->counter($countries, 'Countries Served'),
        ]);
    }

    /**
     * One counter tile, or null when there is nothing real to show.
     *
     * The "+" suffix is only added once a figure is large enough to be a
     * genuine approximation; below that the exact count is shown, because
     * "13+" reads as rounding when it is simply 13.
     *
     * @return array{value: string, label: string}|null
     */
    private function counter(int $actual, string $label): ?array
    {
        if ($actual <= 0) {
            return null;
        }

        $value = $actual >= 100
            ? number_format((int) (floor($actual / 100) * 100)).'+'
            : number_format($actual);

        return ['value' => $value, 'label' => $label];
    }

    /**
     * "Browse Timber Products" category rail (mobile mockup).
     *
     * @return list<array{type: ProductType, title: string, subtitle: string, image: string}>
     */
    private function categories(): array
    {
        return [
            ['type' => ProductType::SawnTimber, 'title' => 'Sawn Timber', 'subtitle' => 'KD, FAS, etc.', 'image' => 'products/iroko-sawn-timber.jpg'],
            ['type' => ProductType::Logs, 'title' => 'Logs', 'subtitle' => 'Export Quality', 'image' => 'products/iroko-logs.jpg'],
            ['type' => ProductType::Veneer, 'title' => 'Veneer', 'subtitle' => 'Rotary & Sliced', 'image' => 'products/sapele-veneer.jpg'],
            ['type' => ProductType::Flooring, 'title' => 'Flooring', 'subtitle' => 'Solid & Engineered', 'image' => 'products/tali-flooring.jpg'],
            ['type' => ProductType::Decking, 'title' => 'Decking', 'subtitle' => 'Outdoor Grade', 'image' => 'products/azobe-decking.jpg'],
        ];
    }

    /**
     * Visible FAQ copy. Rendered on the page *and* emitted as FAQPage JSON-LD —
     * answer engines heavily discount structured answers with no on-page match.
     *
     * @return list<array{q: string, a: string}>
     */
    private function faqs(): array
    {
        return [
            [
                'q' => 'How do I verify that a Cameroon timber supplier is legitimate?',
                'a' => 'Every supplier on Cameroon Timber Hub carries a "Verified Supplier" badge only after we check their trade registry (RCCM) entry, tax identification, and SIGIF II operator ID and permit numbers against public records. Open any company profile to see the badge reference code, the issuing date and the verification expiry, and ask the supplier for matching export documentation before you pay a deposit.',
            ],
            [
                'q' => 'What is FLEGT and does Cameroon timber need it?',
                'a' => 'FLEGT (Forest Law Enforcement, Governance and Trade) is the EU framework for proving timber was harvested legally. Cameroon has signed a Voluntary Partnership Agreement with the EU, and legality is evidenced through the national SIGIF II traceability system plus permit and transport documents. Buyers importing into the EU must also satisfy the EU Deforestation Regulation, so ask your supplier for geolocation data on the harvest plot.',
            ],
            [
                'q' => 'What are typical minimum order quantities for Cameroon hardwood?',
                'a' => 'Sawn timber and logs are usually quoted per cubic metre with a minimum of 20 m³ — roughly one 20ft container. Veneer is quoted per square metre from about 500 m², and plywood or mouldings are quoted per piece from around 100 to 200 pieces. Each product page on the marketplace shows the supplier\'s own MOQ, and many suppliers will consolidate several species into one container.',
            ],
            [
                'q' => 'How long does export from Cameroon take?',
                'a' => 'Allow two to four weeks for production and kiln drying, one to two weeks for documentation and customs clearance, and then roughly 18 to 25 days of sailing time from Douala or Kribi to Northern Europe, 30 to 40 days to Asia and 25 to 35 days to North America. Air-dried and stock items ship faster than made-to-order kiln-dried parcels.',
            ],
            [
                'q' => 'Which timber species does Cameroon export most?',
                'a' => 'The highest-volume Cameroon export species are Ayous (Obeche), Sapele, Iroko, Tali, Azobé, Padouk, Doussie and Wenge. Ayous dominates by volume for joinery and core stock, while Iroko, Tali and Azobé are the durable choices for decking, marine and heavy construction work.',
            ],
            [
                'q' => 'How does an RFQ work on Cameroon Timber Hub?',
                'a' => 'Post one request for quotation describing the species, product type, dimensions, grade, volume and destination port. We route it to every verified supplier that matches, and you receive competing quotations to compare side by side — no per-supplier enquiries and no obligation to buy.',
            ],
        ];
    }

    /**
     * ItemList of the featured catalogue plus the visible FAQ, as a JSON-LD
     *
     * @graph so answer engines can enumerate products and lift Q&A pairs.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     */
    private function schema(Collection $products): array
    {
        $items = $products->values()->map(fn (Product $product, int $i) => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'item' => array_filter([
                '@type' => 'Product',
                'name' => $product->name,
                'url' => route('products.show', $product->slug),
                'image' => $product->primary_image_path ? asset('img/'.$product->primary_image_path) : null,
                'category' => $product->product_type?->label(),
                'material' => $product->species?->common_name,
                'offers' => $product->price_amount === null ? null : [
                    '@type' => 'Offer',
                    'price' => (string) $product->price_amount,
                    'priceCurrency' => $product->price_currency ?: 'XAF',
                    'availability' => 'https://schema.org/InStock',
                    'url' => route('products.show', $product->slug),
                    'seller' => [
                        '@type' => 'Organization',
                        'name' => $product->company?->name,
                    ],
                ],
            ]),
        ])->all();

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'ItemList',
                    'name' => 'Popular Cameroon timber products',
                    'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
                    'numberOfItems' => count($items),
                    'itemListElement' => $items,
                ],
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(fn (array $faq) => [
                        '@type' => 'Question',
                        'name' => $faq['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a']],
                    ], $this->faqs()),
                ],
            ],
        ];
    }
}
