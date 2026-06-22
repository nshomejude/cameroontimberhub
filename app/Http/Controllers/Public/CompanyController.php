<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\View\View;

class CompanyController extends Controller
{
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
            ])
            ->firstOrFail();

        $badge = $company->activeBadges->sortByDesc('issued_at')->first();

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $company->name,
            'url' => route('companies.show', $company->slug),
            'description' => str(strip_tags((string) $company->description))->limit(300)->value(),
            'address' => [
                '@type' => 'PostalAddress',
                'addressRegion' => $company->region,
                'addressLocality' => $company->city,
                'addressCountry' => $company->country_code,
            ],
        ];

        if ($company->logo_path) {
            $schema['logo'] = asset('storage/'.$company->logo_path);
        }

        return view('public.companies.show', [
            'company' => $company,
            'badge' => $badge,
            'schema' => $schema,
        ]);
    }
}
