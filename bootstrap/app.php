<?php

use App\Http\Middleware\EnsureBuyerAccount;
use App\Http\Middleware\EnsureExporterOnboarded;
use App\Http\Middleware\HandleSlugRedirects;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleSlugRedirects::class,
        ]);

        // Already-authenticated visitors hitting /login or /register go home;
        // the post-auth redirect rule then applies on their next real login.
        $middleware->redirectUsersTo('/');

        $middleware->alias([
            'exporter.onboarded' => EnsureExporterOnboarded::class,
            'buyer' => EnsureBuyerAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
