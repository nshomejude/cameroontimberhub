<?php

use App\Http\Middleware\SecurityHeaders;

it('sends the baseline security headers on GET /', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    expect($response->headers->get('Referrer-Policy'))->not->toBeNull();
    expect($response->headers->get('Permissions-Policy'))->toContain('geolocation=(self)');
});

it('sends a report-only CSP (never an enforcing one) on GET /', function () {
    $response = $this->get('/');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->not->toBeNull()
        ->toContain("default-src 'self'")
        ->toContain('report-uri /csp-report');

    // Must stay report-only until the follow-up hardening task.
    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('sends HSTS with a >= 1 year max-age only over HTTPS', function () {
    $secure = $this->get('https://localhost/');

    $hsts = $secure->headers->get('Strict-Transport-Security');
    expect($hsts)->not->toBeNull();

    preg_match('/max-age=(\d+)/', (string) $hsts, $m);
    expect((int) ($m[1] ?? 0))->toBeGreaterThanOrEqual(31536000);
});

it('does not send HSTS over plain HTTP (local dev safety)', function () {
    $response = $this->get('http://localhost/');

    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('keeps the health endpoint reachable with the web middleware in place', function () {
    $this->get('/up/health')->assertOk();
});

it('exposes the CSP string as a single source of truth', function () {
    expect(SecurityHeaders::CSP)
        ->toContain("frame-ancestors 'self'")
        ->toContain("connect-src 'self'");
});
