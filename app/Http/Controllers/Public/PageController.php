<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Page;
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

        return view("public.pages.{$page->template}", $data);
    }
}
