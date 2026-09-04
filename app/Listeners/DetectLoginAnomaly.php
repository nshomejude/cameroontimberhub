<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\FraudDetectionService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Blueprint §25 login-anomaly detection, wired on Laravel's built-in
 * Illuminate\Auth\Events\Login event (fired for every guard, admin and
 * exporter panels included).
 *
 * Runs the anomaly check against the user's PRIOR login history, then
 * records this login (last_login_at/ip/user_agent + a rolling
 * known_login_ips list) so future logins have something to compare against.
 * Entirely wrapped in try/catch: a bug here must never prevent a real,
 * already-authenticated login from completing.
 */
class DetectLoginAnomaly
{
    public function __construct(private readonly FraudDetectionService $fraud) {}

    public function handle(Login $event): void
    {
        try {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            $ip = (string) (Request::ip() ?? '');
            $userAgent = Request::userAgent();

            $this->fraud->detectLoginAnomaly($user, $ip, $userAgent);

            $this->recordLogin($user, $ip, $userAgent);
        } catch (Throwable $e) {
            Log::error('DetectLoginAnomaly failed to process login', [
                'user_id' => $event->user?->getAuthIdentifier() ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function recordLogin(User $user, string $ip, ?string $userAgent): void
    {
        $knownIps = collect($user->known_login_ips ?? [])->filter()->values();

        if ($ip !== '' && ! $knownIps->contains($ip)) {
            $knownIps->push($ip);
        }

        $knownIps = $knownIps->slice(-FraudDetectionService::KNOWN_IP_HISTORY_LIMIT)->values();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip ?: null,
            'last_login_user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            'known_login_ips' => $knownIps->all(),
        ])->saveQuietly();
    }
}
