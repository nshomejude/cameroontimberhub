<?php

namespace App\Http\Controllers\Public;

use App\Enums\OrganisationType;
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
                'capacities',
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

        // Company-generated content shown on the profile's own tabs — mirrors
        // the products query's "publicly showable" filtering. Capacity rows
        // have no status column of their own (visibility is owner-gated only),
        // so every row belonging to this company is shown. Carbon projects
        // reuse ProductStatus and only Active rows are public.
        $capacities = $company->capacities;

        $carbonProjects = $company->type === OrganisationType::CarbonDeveloper
            ? $company->carbonProjects()->where('status', ProductStatus::Active->value)->get()
            : collect();

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
            'capacities' => $capacities,
            'carbonProjects' => $carbonProjects,
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
     * Public portfolio page for an artisan/professional company — the
     * completed-work gallery items flagged `is_portfolio`. Gated to
     * `OrganisationType::Artisan` companies; anything else 404s, matching
     * show()'s "do not disclose" pattern for non-public companies.
     */
    public function portfolio(string $slug): View
    {
        $company = Company::publiclyVisible()
            ->where('slug', $slug)
            ->where('type', OrganisationType::Artisan->value)
            ->with(['portfolioItems'])
            ->firstOrFail();

        return view('public.companies.portfolio', [
            'company' => $company,
            'items' => $company->portfolioItems,
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
            'telephone' => $this->realPhone($company->phone),
            'foundingDate' => $company->year_founded ? (string) $company->year_founded : null,
            'numberOfEmployees' => $company->employee_count,
            'areaServed' => $company->exportMarkets->pluck('country_code')->filter()->values()->all() ?: null,
            'knowsLanguage' => is_array($company->languages) && $company->languages !== [] ? $company->languages : null,
            'sameAs' => $company->socialLinks->pluck('url')->filter(fn ($u) => $this->realUrl($u) !== null)->values()->all() ?: null,
            'hasCredential' => $badges->map(fn ($b) => $b->badge_type?->label())->filter()->values()->all() ?: null,
        ], fn ($v) => $v !== null && $v !== []);

        if ($company->logo_path) {
            $schema['logo'] = $company->logoUrl();
        }

        if ($this->realUrl($company->website_url) !== null) {
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

        return $this->isPlaceholderHost(Str::afterLast($email, '@')) ? null : $email;
    }

    /**
     * Same guard for a website / social URL — a `https://acme.example`
     * placeholder must not reach `url` / `sameAs` in the schema.
     */
    private function realUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return $this->isPlaceholderHost($host) ? null : $url;
    }

    /**
     * Drop an obviously-fake phone number (all-zero, 123456789, +1234567890
     * and the like) so it is never published beside a "Verified Exporter"
     * credential claim.
     */
    private function realPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) < 6 || preg_match('/^0+$/', $digits) || str_contains($digits, '123456789')) {
            return null;
        }

        return $phone;
    }

    /** RFC 2606 / RFC 6761 reserved placeholder hosts. */
    private function isPlaceholderHost(string $host): bool
    {
        $host = strtolower(trim(rtrim($host, '.')));
        $host = Str::startsWith($host, 'www.') ? Str::after($host, 'www.') : $host;

        return in_array($host, ['example.com', 'example.net', 'example.org', 'example', 'test', 'localhost', 'invalid'], true)
            || Str::endsWith($host, ['.example', '.test', '.invalid', '.localhost']);
    }
}
