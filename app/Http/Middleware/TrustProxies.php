<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trusts X-Forwarded-For/Proto/Host/Port from the reverse proxies listed in
 * config('app.trusted_proxies') (env TRUSTED_PROXIES): a comma-separated
 * list of IPs/CIDRs, or '*' to trust whichever peer connects (only safe when
 * the PHP port is unreachable except through the proxy, e.g. Cloudflare →
 * nginx on the same host). Defaults to loopback, which covers nginx → php-fpm
 * on one box.
 *
 * Without this, every visitor behind nginx shares the proxy's IP — so all
 * per-IP throttles share one bucket — and Request::secure() is false, so the
 * HSTS header in SecurityHeaders never fires.
 */
class TrustProxies extends Middleware
{
    /** @var int */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', '127.0.0.1,::1'));

        if ($configured === '*' || $configured === '**') {
            return $configured;
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $configured))));

        return $list === [] ? null : $list;
    }
}
