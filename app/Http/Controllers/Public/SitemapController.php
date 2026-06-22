<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Species;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('directory'), 'priority' => '0.9'],
            ['loc' => route('species.index'), 'priority' => '0.8'],
        ];

        // Programmatic-SEO species pages (published only).
        foreach (Species::published()->get(['slug', 'updated_at']) as $species) {
            $urls[] = [
                'loc' => route('species.show', $species->slug),
                'lastmod' => optional($species->updated_at)->toAtomString(),
                'priority' => '0.8',
            ];
        }

        // Company profiles — only those publicly visible (the single gate).
        foreach (Company::publiclyVisible()->get(['slug', 'updated_at']) as $company) {
            $urls[] = [
                'loc' => route('companies.show', $company->slug),
                'lastmod' => optional($company->updated_at)->toAtomString(),
                'priority' => '0.7',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>';
            if (! empty($url['lastmod'])) {
                $xml .= '<lastmod>'.$url['lastmod'].'</lastmod>';
            }
            $xml .= '<priority>'.$url['priority'].'</priority></url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Sitemap: '.route('sitemap'),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
