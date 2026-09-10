<?php

namespace App\Exceptions\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single place `/api/v1` (and any `api/*`) error bodies are shaped
 * (architecture GAPS.md §4).
 *
 * Every error — whatever threw it — leaves as:
 *
 *   {
 *     "error": {
 *       "code": "quote_not_actionable",     // stable snake_case machine code
 *       "message": "This quote is declined and can no longer be actioned.",
 *       "request_id": "01J...",             // always present (AssignRequestId)
 *       "details": { "field": ["..."] }     // 422 only
 *     }
 *   }
 *
 * Wired from bootstrap/app.php's `withExceptions()` via
 * `ErrorEnvelope::render($e, $request)`, gated on `$request->is('api/*')`.
 */
class ErrorEnvelope
{
    /**
     * @return JsonResponse|null  null → let the default handler deal with it
     *                            (should not happen for api/* but is safe)
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        [$status, $code, $message, $details] = self::classify($e);

        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        $requestId = self::requestId($request);
        if ($requestId !== null) {
            $error['request_id'] = $requestId;
        }

        $response = new JsonResponse(['error' => $error], $status);

        // Preserve transport headers the framework attached to the exception
        // (notably Retry-After / X-RateLimit-* on a 429).
        if ($e instanceof HttpExceptionInterface) {
            $response->headers->add($e->getHeaders());
        }

        return $response;
    }

    /**
     * @return array{0:int,1:string,2:string,3:array<string,array<int,string>>|null}
     */
    private static function classify(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return [$e->status, $e->errorCode, $e->getMessage(), $e->details];
        }

        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', $e->getMessage(), $e->errors()];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', 'Unauthenticated.', null];
        }

        if ($e instanceof AuthorizationException && ! $e instanceof HttpExceptionInterface) {
            return [403, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.', null];
        }

        if ($e instanceof ModelNotFoundException) {
            return [404, 'not_found', 'Resource not found.', null];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = trim((string) $e->getMessage());

            return match ($status) {
                401 => [401, 'unauthenticated', $message ?: 'Unauthenticated.', null],
                403 => [403, 'forbidden', $message ?: 'This action is unauthorized.', null],
                404 => [404, 'not_found', $message ?: 'Resource not found.', null],
                405 => [405, 'method_not_allowed', $message ?: 'Method not allowed.', null],
                409 => [409, 'conflict', $message ?: 'Conflict.', null],
                429 => [429, 'rate_limited', $message ?: 'Too Many Attempts.', null],
                default => $status >= 500
                    ? self::serverError($e)
                    : [$status, self::genericCodeFor($status), $message ?: 'Request failed.', null],
            };
        }

        return self::serverError($e);
    }

    /**
     * 500s never leak the exception message unless debug mode is on — in
     * production the client gets one fixed generic string.
     *
     * @return array{0:int,1:string,2:string,3:null}
     */
    private static function serverError(Throwable $e): array
    {
        $message = config('app.debug')
            ? ($e->getMessage() ?: 'Server error.')
            : 'An unexpected error occurred.';

        return [500, 'server_error', $message, null];
    }

    private static function genericCodeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            406 => 'not_acceptable',
            410 => 'gone',
            415 => 'unsupported_media_type',
            default => 'error',
        };
    }

    private static function requestId(Request $request): ?string
    {
        $fromRequest = $request->attributes->get('request_id');
        if (is_string($fromRequest) && $fromRequest !== '') {
            return $fromRequest;
        }

        $fromContext = Context::get('request_id');

        return is_string($fromContext) && $fromContext !== '' ? $fromContext : null;
    }
}
