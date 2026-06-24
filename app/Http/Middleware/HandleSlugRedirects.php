<?php

namespace App\Http\Middleware;

use App\Models\SlugRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Performs 301 redirects for old slugs recorded in slug_redirects.
 * Runs early in the web stack so it intercepts before route resolution.
 * Only fires on GET/HEAD requests to avoid touching form submissions.
 */
class HandleSlugRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $path = ltrim($request->getPathInfo(), '/');

            if ($path !== '') {
                $redirect = SlugRedirect::where('from_slug', $path)->first();

                if ($redirect) {
                    return redirect($redirect->to_url, 301);
                }
            }
        }

        return $next($request);
    }
}
