<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyExportMarket;
use App\Models\Page;
use App\Models\Product;
use App\Models\Species;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
     * Renders a CMS page from the `pages` table by its slug.
     * The slug is provided either as a route parameter ({slug} in URL)
     * or as a route default via ->defaults('slug', '...').
     */
    public function show(string $slug): View
    {
        $page = Page::where('slug', $slug)->where('is_published', true)->firstOrFail();

        $data = ['page' => $page];

        if ($page->template === 'landing') {
            $data['companies'] = Company::publiclyVisible()
                ->with(['species:id,slug,common_name'])
                ->orderByDesc('is_featured')
                ->orderByDesc('verified_at')
                ->paginate(12);
        }

        if ($page->template === 'about') {
            $data['stats'] = $this->platformStats();
            $data['schema'] = $this->aboutSchema($page);
            $data['breadcrumbs'] = [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => $page->title, 'url' => url()->current()],
            ];
        }

        return view("public.pages.{$page->template}", $data);
    }

    /**
     * Counters for the About page. Every figure is a live aggregate over
     * publicly visible records — no mockup numbers are baked in, and a counter
     * whose underlying query returns zero is dropped rather than padded, so
     * the page never states something the database cannot support.
     *
     * @return list<array{value: string, label: string, icon: string}>
     */
    private function platformStats(): array
    {
        $candidates = [
            [
                'count' => Company::publiclyVisible()->count(),
                'label' => 'Verified suppliers',
                'icon' => 'building-office-2',
            ],
            [
                'count' => Species::published()->count(),
                'label' => 'Timber species catalogued',
                'icon' => 'sparkles',
            ],
            [
                'count' => CompanyExportMarket::query()
                    ->whereHas('company', fn ($c) => $c->publiclyVisible())
                    ->distinct()
                    ->count('country_code'),
                'label' => 'Export markets reached',
                'icon' => 'globe-alt',
            ],
            [
                'count' => Product::active()
                    ->whereHas('company', fn ($c) => $c->publiclyVisible())
                    ->count(),
                'label' => 'Products listed',
                'icon' => 'cube',
            ],
        ];

        return collect($candidates)
            ->filter(fn (array $stat) => $stat['count'] > 0)
            ->map(fn (array $stat) => [
                'value' => number_format($stat['count']),
                'label' => $stat['label'],
                'icon' => $stat['icon'],
            ])
            ->values()
            ->all();
    }

    /**
     * AboutPage + Organization JSON-LD. `sameAs` lists only social profiles
     * that are actually configured (config/contact.php), so we never assert a
     * presence on a network the business does not have.
     *
     * @return array<string, mixed>
     */
    private function aboutSchema(Page $page): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'AboutPage',
            'name' => $page->title,
            'url' => url()->current(),
            'description' => $page->meta_description,
            'mainEntity' => array_filter([
                '@type' => 'Organization',
                '@id' => url('/#organization'),
                'name' => config('contact.organisation', config('app.name')),
                'url' => url('/'),
                'logo' => url('/brand/logo-600.png'),
                'description' => $page->meta_description,
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'addressLocality' => config('contact.address.locality'),
                    'addressRegion' => config('contact.address.region'),
                    'addressCountry' => config('contact.address.country'),
                ]),
                'sameAs' => array_values(array_filter(config('contact.social', []))) ?: null,
            ]),
        ];
    }
}
