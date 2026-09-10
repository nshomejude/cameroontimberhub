<?php

namespace App\Exceptions\Api;

use RuntimeException;
use Throwable;

/**
 * Base for deliberately-surfaced `/api/v1` errors.
 *
 * The point of this class is that the JSON error body is shaped in exactly ONE
 * place — the renderer registered in `bootstrap/app.php` — not hand-rolled with
 * `response()->json(['message' => ...], $status)` in each controller. A
 * controller throws one of these (or a subclass) with a stable machine `code`
 * and a human `message`; the renderer turns it into the standard envelope:
 *
 *   { "error": { "code": ..., "message": ..., "request_id": ..., "details"? } }
 *
 * Status code and human message are unchanged from the pre-envelope behaviour —
 * this is a reshape of the body, not a change of semantics.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  int  $status  HTTP status code
     * @param  string  $errorCode  stable snake_case machine code
     * @param  string  $publicMessage  human-readable, safe to show the client
     * @param  array<string, array<int, string>>|null  $details  field errors (422 only)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $publicMessage,
        public readonly ?array $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }
}
