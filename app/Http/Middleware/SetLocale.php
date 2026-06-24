<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // MVP: English only. V2: read from user->locale, Accept-Language, or session.
        app()->setLocale(config('app.locale', 'en'));

        return $next($request);
    }
}
