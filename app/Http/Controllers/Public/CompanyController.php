<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\VerificationBadge;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CompanyController extends Controller
{
    /** How many products the profile shows before deferring to the marketplace. */
    private const PRODUCT_PREVIEW = 8;

    private const REVIEW_PREVIEW = 10;

    public function show(string $slug): View
    {
        // Resolve only through the public-visibility scope; anything else is a
        // 404 (we do not disclose the existence of hidden/pending companies).
        $company = Company::publiclyVisible()
            ->where('slug', $slug)
            ->with([
                'species',
                'exportMarkets',
                'gallery',
                'socialLinks',
                'contacts' => fn ($q) => $q->where('is_public', true),
                'activeBadges',
                // Public + approved + unexpired only. Private, admin-only and
                // buyer-gated documents never reach a public response.
                'publicDocuments.documentType',
            ])
            ->firstOrFail();

        $badges = $company->activeBadges->where('is_public', true)->sortByDesc('issued_at')->values();
        $badge = $badges->first();

        $productQuery = $company->products()->where('status', ProductStatus::Active->value);

        $productCount = (clone $productQuery)->count();

        $products = (clone $productQuery)
            ->with(['species:id,slug,common_name', 'company:id,slug,legal_name,trade_name,city,region,status,logo_path'])
            ->orderByDesc('is_featured')
            ->orderByDesc('is_best_seller')
            ->orderBy('name')
            ->limit(self::PRODUCT_PREVIEW)
            ->get();

        // Category counts are real counts over this supplier's active listings.
        $categories = (clone $productQuery)
            ->selectRaw('product_type, count(*) as aggregate')
            ->groupBy('product_type')
            ->pluck('aggregate', 'product_type')
            ->map(fn ($count, $type) => [
                'label' => ProductType::tryFrom((string) $type)?->label() ?? (string) $type,
                'count' => (int) $count,
                'url' => route('marketplace', ['supplier' => $company->slug, 'types' => [$type]]),
            ])
            ->sortByDesc('count')
            ->values();

        $breadcrumbs = [
            ['label' => 'Home', 'url' => route('home')],
            ['label' => 'Suppliers', 'url' => route('directory')],
            ['label' => $company->name, 'url' => route('companies.show', $company->slug)],
        ];

        return view('public.companies.show', [
            'company' => $company,
            'badge' => $badge,
            'badges' => $badges,
            'products' => $products,
            'productCount' => $productCount,
            'categories' => $categories,
            'documents' => $company->publicDocuments,
            // Real buyer reviews, each one earned by a completed order. Before
            // Phase 3 there was no reviews table at all and this section did
            // not exist; `rating_avg` / `rating_count` are now recomputed from
            // exactly these rows by CompanyReviewService.
            'reviews' => $company->publishedReviews()->with('order:id,reference_code')->limit(self::REVIEW_PREVIEW)->get(),
            'reviewCount' => $company->reviews()->published()->count(),
            'breadcrumbs' => $breadcrumbs,
            'schema' => $this->schema($company, $badges, $productCount),
        ]);
    }

    /**
     * Organization JSON-LD. Every property is dropped when it has no real
     * value, and `aggregateRating` is emitted only when a genuine buyer rating
     * exists — never a zero-star placeholder.
     *
     * @param  Collection<int, VerificationBadge>  $badges
     * @return array<string, mixed>
     */
    private function schema(Company $company, $badges, int $productCount): array
    {
        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $company->address_line,
            'addressLocality' => $company->city,
            'addressRegion' => $company->region,
            'addressCountry' => $company->country_code,
        ]);

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'url' => route('companies.show', $company->slug),
            'description' => Str::limit(strip_tags((string) $company->description), 300),
            'address' => count($address) > 1 ? $address : null,
            'email' => $this->realEmail($company->email),
            'telephone' => $company->phone,
            'foundingDate' => $company->year_founded ? (string) $company->year_founded : null,
            'numberOfEmployees' => $company->employee_count,
            'areaServed' => $company->exportMarkets->pluck('country_code')->filter()->values()->all() ?: null,
            'knowsLanguage' => is_array($company->languages) && $company->languages !== [] ? $company->languages : null,
            'sameAs' => $company->socialLinks->pluck('url')->filter()->values()->all() ?: null,
            'hasCredential' => $badges->map(fn ($b) => $b->badge_type?->label())->filter()->values()->all() ?: null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($company->logo_path) {
            $schema['logo'] = $company->logoUrl();
        }

        if ($company->website_url) {
            $schema['sameAs'] = array_values(array_unique(array_merge($schema['sameAs'] ?? [], [$company->website_url])));
        }

        if ($productCount > 0) {
            $schema['makesOffer'] = [
                '@type' => 'Offer',
                'itemOffered' => ['@type' => 'Product', 'name' => $company->name.' timber products'],
                'url' => route('marketplace', ['supplier' => $company->slug]),
            ];
        }

        // Only when real rating data exists behind it.
        if ($company->hasRating()) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $company->rating_avg,
                'reviewCount' => (int) $company->rating_count,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return $schema;
    }

    /**
     * Never let a reserved-for-documentation placeholder address (RFC 2606:
     * example.com/.net/.org and any *.example host, e.g.
     * "sales@africanwood.example") reach live Organization JSON-LD as if it
     * were a real contact — omit the field instead of publishing a falsehood
     * beside a `hasCredential: "Verified Exporter"` claim.
     */
    private function realEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        $domain = strtolower(Str::afterLast($email, '@'));

        if ($domain === 'example.com' || $domain === 'example.net' || $domain === 'example.org') {
            return null;
        }

        if (Str::endsWith($domain, '.example') || $domain === 'test' || Str::endsWith($domain, '.test') || Str::endsWith($domain, '.invalid')) {
            return null;
        }

        return $email;
    }
}
