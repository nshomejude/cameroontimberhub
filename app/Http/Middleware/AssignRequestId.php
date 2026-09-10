<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request correlation id (architecture GAPS.md §6).
 *
 * On every request:
 *   - accept an inbound `X-Request-Id` header IFF it is a well-formed ULID or
 *     UUID (anything else — wrong length, junk, an attempt to inject log noise
 *     — is ignored and a fresh id generated instead);
 *   - otherwise generate a fresh ULID;
 *   - stash it on the request attribute bag as `request_id` AND in Laravel's
 *     Context (so the exception renderer in bootstrap/app.php and
 *     AppServiceProvider's activity-log stamper can both reach it without
 *     threading it through call signatures);
 *   - echo it back as the `X-Request-Id` response header.
 *
 * Context is request/job-scoped and auto-cleared between requests, so there is
 * no bleed between requests on a worker. Console/queue contexts never run this
 * middleware, so `Context::get('request_id')` is simply absent there — callers
 * must treat it as nullable.
 */
class AssignRequestId
{
    /** Max length we will even consider — a UUID is 36 chars, a ULID 26. */
    private const MAX_INBOUND_LENGTH = 36;

    public function handle(Request $request, Closure $next): Response
    {
        // Idempotent: if an earlier pass (e.g. this middleware on both the
        // global `api`/`web` group and a route group) already assigned one,
        // keep it rather than minting a second id for the same request.
        $existing = $request->attributes->get('request_id');

        $id = is_string($existing) && $existing !== ''
            ? $existing
            : $this->resolveId($request);

        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    private function resolveId(Request $request): string
    {
        $inbound = $request->headers->get('X-Request-Id');

        if (is_string($inbound)
            && strlen($inbound) <= self::MAX_INBOUND_LENGTH
            && (Str::isUlid($inbound) || Str::isUuid($inbound))) {
            return $inbound;
        }

        return (string) Str::ulid();
    }
}
