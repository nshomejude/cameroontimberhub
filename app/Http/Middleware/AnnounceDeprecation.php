<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Emits standards-compliant deprecation signalling on the responses of any
 * route or group it is applied to. Closes GAPS.md gap 5: the reusable
 * mechanism the platform needs the first time a `/api/v1` endpoint (or all of
 * v1) has to be sunset. Nothing is deprecated today — this middleware is not
 * applied to any real route (see routes/api.php for the example declaration).
 *
 * Headers added (RFC 8594 / draft-ietf-httpapi-deprecation-header):
 *
 *   Deprecation: <HTTP-date>   the date the endpoint became deprecated,
 *                              or `true` when no / an unparseable date is given
 *   Sunset: <HTTP-date>        the date after which the endpoint may stop
 *                              responding (omitted when absent/unparseable)
 *   Link: <url>; rel="successor-version"   the successor endpoint, when given
 *   Warning: 299 - "<note>"    optional human-readable note
 *
 * Applied as `->middleware('deprecated:<deprecation>,<sunset>,<successor>,<note>')`.
 * Every parameter is optional and parsed defensively — a missing or malformed
 * part never 500s the request, it just degrades:
 *   - bad / missing deprecation date  -> `Deprecation: true`
 *   - bad / missing sunset date        -> no `Sunset` header
 *   - missing successor URL            -> no `Link` header
 *   - missing note                     -> no `Warning` header
 *
 * Dates accept anything Carbon parses (ISO `2027-01-01`, an HTTP-date, etc.)
 * and are always emitted in IMF-fixdate / HTTP-date format, e.g.
 * `Fri, 01 Jan 2027 00:00:00 GMT`.
 */
class AnnounceDeprecation
{
    public function handle(
        Request $request,
        Closure $next,
        ?string $deprecationDate = null,
        ?string $sunsetDate = null,
        ?string $successorUrl = null,
        ?string $note = null,
    ): Response {
        $response = $next($request);

        try {
            $response->headers->set('Deprecation', $this->httpDate($deprecationDate) ?? 'true');

            if ($sunset = $this->httpDate($sunsetDate)) {
                $response->headers->set('Sunset', $sunset);
            }

            if (is_string($successorUrl) && trim($successorUrl) !== '') {
                $response->headers->set(
                    'Link',
                    '<'.trim($successorUrl).'>; rel="successor-version"',
                    false,
                );
            }

            if (is_string($note) && trim($note) !== '') {
                $response->headers->set('Warning', '299 - "'.str_replace('"', "'", trim($note)).'"');
            }
        } catch (Throwable) {
            // Signalling is best-effort — never break a live response over it.
        }

        return $response;
    }

    /**
     * Parse a loose date string into an IMF-fixdate / HTTP-date, or null when
     * it is missing or unparseable.
     */
    private function httpDate(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))
                ->utc()
                ->toRfc7231String();
        } catch (Throwable) {
            return null;
        }
    }
}
