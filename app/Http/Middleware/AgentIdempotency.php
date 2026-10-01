<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Idempotency-Key` support for agent POST endpoints (alias `agent.idempotent`).
 *
 * Optional: without the header the request runs normally. With it, the first
 * request for a (token, key) pair is executed and its JSON response stored;
 * a retry with the SAME payload replays that response verbatim (plus an
 * `Idempotent-Replayed: true` header) without re-executing; a retry with a
 * DIFFERENT payload is a 409 `idempotency_conflict`; a retry while the first
 * is still running is a 409 `idempotency_in_progress`. 5xx responses are not
 * stored, so the agent can safely retry them. Records live 7 days
 * (`agent:prune-idempotency-keys`).
 */
class AgentIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        if ($key === '' || $tokenId === null || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (strlen($key) > 255) {
            throw new ApiException(422, 'validation_failed', 'Idempotency-Key must be at most 255 characters.');
        }

        $hash = $this->hash($request);

        $inserted = DB::table('agent_idempotency_keys')->insertOrIgnore([
            'token_id' => $tokenId,
            'key' => $key,
            'request_hash' => $hash,
            'status' => null,
            'body' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            $row = DB::table('agent_idempotency_keys')->where('token_id', $tokenId)->where('key', $key)->first();

            if ($row === null) {
                throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.');
            }

            if (! hash_equals((string) $row->request_hash, $hash)) {
                throw new ApiException(409, 'idempotency_conflict', 'This Idempotency-Key was already used with a different request payload.');
            }

            if ($row->status === null) {
                throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.');
            }

            return (new JsonResponse(json_decode((string) $row->body, true), (int) $row->status))
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->forget($tokenId, $key);

            throw $e;
        }

        if ($response->getStatusCode() >= 500 || ! $response instanceof JsonResponse) {
            $this->forget($tokenId, $key);

            return $response;
        }

        DB::table('agent_idempotency_keys')->where('token_id', $tokenId)->where('key', $key)->update([
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function forget(int|string $tokenId, string $key): void
    {
        DB::table('agent_idempotency_keys')->where('token_id', $tokenId)->where('key', $key)->delete();
    }

    /** Stable fingerprint of method + path + payload (+ uploaded file contents). */
    private function hash(Request $request): string
    {
        $payload = $request->input();
        $this->ksortRecursive($payload);

        $files = collect($request->allFiles())
            ->flatten()
            ->filter(fn ($f) => $f instanceof UploadedFile)
            ->map(fn (UploadedFile $f) => hash_file('sha256', $f->getRealPath()))
            ->values()
            ->all();

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload).'|'.implode(',', $files));
    }

    /** @param  array<mixed>  $a */
    private function ksortRecursive(array &$a): void
    {
        foreach ($a as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
        ksort($a);
    }
}
